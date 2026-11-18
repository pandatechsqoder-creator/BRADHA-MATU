<?php

namespace App\Services\Marketing;

use App\Exceptions\MarketingIntegrationException;
use App\Models\Marketing\AutomationRun;
use App\Models\Marketing\AutomationRunStep;
use App\Models\Marketing\AutomationStep;
use App\Models\Marketing\AutomationWorkflow;
use App\Models\Marketing\MarketingConsent;
use App\Models\Marketing\MarketingLead;
use App\Models\Marketing\MarketingSetting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AutomationService
{
    public const KILL_SWITCH_KEY = 'automation.enabled';

    public const KILL_SWITCH_MESSAGE = 'Automation is paused by the kill switch, so this event was recorded and no steps ran.';

    public const MAX_ATTEMPTS = 3;

    public const BACKOFF_MINUTES = [1, 5, 15];

    private const PROVIDER_MESSAGE = 'No outbound email, SMS or WhatsApp provider has been approved, so the message was held instead of sent.';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        $setting = MarketingSetting::query()->where('key', self::KILL_SWITCH_KEY)->first();

        if ($setting === null || $setting->value === null) {
            return true;
        }

        $value = $setting->value;

        return is_array($value) ? (bool) ($value['enabled'] ?? true) : (bool) $value;
    }

    public function setEnabled(bool $enabled, User $actor): bool
    {
        $before = $this->enabled();

        MarketingSetting::query()->updateOrCreate(
            ['key' => self::KILL_SWITCH_KEY],
            ['group' => 'automation', 'value' => ['enabled' => $enabled], 'is_public' => false, 'updated_by' => $actor->id],
        );

        $this->audit->log(
            'marketing.automation.kill_switch',
            null,
            $enabled ? 'Automation resumed' : 'Automation paused by kill switch',
            ['enabled' => $before],
            ['enabled' => $enabled],
        );

        return $enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogue(): array
    {
        return [
            'enabled' => $this->enabled(),
            'kill_switch' => [
                'enabled' => $this->enabled(),
                'key' => self::KILL_SWITCH_KEY,
                'message' => self::KILL_SWITCH_MESSAGE,
            ],
            'triggers' => collect(AutomationWorkflow::TRIGGERS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'step_types' => [
                ['value' => 'notify_internal', 'label' => 'Notify the team (in-app)'],
                ['value' => 'notify_external', 'label' => 'Notify the lead (email / SMS / WhatsApp)'],
                ['value' => 'add_activity', 'label' => 'Add a lead activity'],
                ['value' => 'wait', 'label' => 'Wait, then continue'],
            ],
            'external_channels' => AutomationStep::EXTERNAL_CHANNELS,
            'outbound_provider' => [
                'approved' => false,
                'message' => self::PROVIDER_MESSAGE,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(array $data, User $user): AutomationWorkflow
    {
        return DB::transaction(function () use ($data, $user): AutomationWorkflow {
            $workflow = AutomationWorkflow::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'trigger' => $data['trigger'],
                'status' => 'draft',
                'conditions' => $this->conditions($data['conditions'] ?? []),
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $this->replaceSteps($workflow, $data['steps'] ?? []);

            return $workflow->fresh(['steps']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AutomationWorkflow $workflow, array $data, User $user): AutomationWorkflow
    {
        if ($workflow->status === 'active') {
            throw new MarketingIntegrationException('AUTOMATION_LOCKED', 'Pause the workflow before editing it so no live run changes mid-flight.', 422);
        }

        DB::transaction(function () use ($workflow, $data, $user): void {
            $workflow->forceFill([
                'name' => $data['name'] ?? $workflow->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $workflow->description,
                'trigger' => $data['trigger'] ?? $workflow->trigger,
                'conditions' => array_key_exists('conditions', $data) ? $this->conditions($data['conditions'] ?? []) : $workflow->conditions,
                'updated_by' => $user->id,
            ])->save();

            if (array_key_exists('steps', $data)) {
                $this->replaceSteps($workflow, $data['steps']);
            }
        });

        return $workflow->fresh(['steps']);
    }

    public function activate(AutomationWorkflow $workflow, User $user): AutomationWorkflow
    {
        if ($workflow->steps()->count() === 0) {
            throw new MarketingIntegrationException('AUTOMATION_STEPS_REQUIRED', 'Add at least one step before activating the workflow.', 422);
        }

        $before = $workflow->status;
        $workflow->forceFill([
            'status' => 'active',
            'activated_at' => now(),
            'paused_at' => null,
            'updated_by' => $user->id,
        ])->save();

        $this->audit->log('marketing.automation.workflow_activated', $workflow, null, ['status' => $before], ['status' => 'active']);

        return $workflow->fresh(['steps']);
    }

    public function pause(AutomationWorkflow $workflow, User $user): AutomationWorkflow
    {
        $before = $workflow->status;
        $workflow->forceFill([
            'status' => 'paused',
            'paused_at' => now(),
            'updated_by' => $user->id,
        ])->save();

        $this->audit->log('marketing.automation.workflow_paused', $workflow, null, ['status' => $before], ['status' => 'paused']);

        return $workflow->fresh(['steps']);
    }

    /**
     * @return Collection<int, AutomationWorkflow>
     */
    public function workflows(): Collection
    {
        return AutomationWorkflow::query()
            ->with(['steps'])
            ->withCount([
                'runs',
                'runs as completed_runs_count' => fn ($query) => $query->where('status', 'completed'),
                'runs as blocked_runs_count' => fn ($query) => $query->where('status', 'blocked'),
                'runs as failed_runs_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(AutomationWorkflow $workflow): array
    {
        $workflow->load(['steps', 'creator:id,uuid,name']);
        $lastRun = $workflow->runs()->first();

        return [
            'id' => $workflow->uuid,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'trigger' => $workflow->trigger,
            'trigger_label' => AutomationWorkflow::TRIGGERS[$workflow->trigger] ?? $workflow->trigger,
            'status' => $workflow->status,
            'conditions' => (object) ($workflow->conditions ?? []),
            'steps' => $workflow->steps->map(fn (AutomationStep $step) => [
                'id' => $step->id,
                'position' => $step->position,
                'type' => $step->type,
                'config' => (object) ($step->config ?? []),
            ])->values()->all(),
            'creator' => $workflow->creator ? ['id' => $workflow->creator->uuid, 'name' => $workflow->creator->name] : null,
            'activated_at' => $workflow->activated_at?->toIso8601String(),
            'paused_at' => $workflow->paused_at?->toIso8601String(),
            'stats' => [
                'runs' => $workflow->runs()->count(),
                'completed' => $workflow->runs()->where('status', 'completed')->count(),
                'blocked' => $workflow->runs()->where('status', 'blocked')->count(),
                'failed' => $workflow->runs()->where('status', 'failed')->count(),
                'waiting' => $workflow->runs()->where('status', 'waiting')->count(),
                'last_run' => $lastRun ? [
                    'status' => $lastRun->status,
                    'created_at' => $lastRun->created_at?->toIso8601String(),
                    'finished_at' => $lastRun->finished_at?->toIso8601String(),
                ] : null,
            ],
            'created_at' => $workflow->created_at?->toIso8601String(),
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function executions(AutomationWorkflow $workflow, int $limit = 50): array
    {
        $runs = $workflow->runs()
            ->with(['steps', 'lead:id,uuid,reference,contact_name,email,assigned_to'])
            ->limit(min(max($limit, 1), 200))
            ->get();

        return [
            'workflow' => ['id' => $workflow->uuid, 'name' => $workflow->name, 'status' => $workflow->status],
            'runs' => $runs->map(fn (AutomationRun $run) => [
                'id' => $run->uuid,
                'status' => $run->status,
                'trigger' => $run->trigger,
                'attempts' => $run->attempts,
                'last_error' => $run->last_error,
                'resume_at' => $run->resume_at?->toIso8601String(),
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'created_at' => $run->created_at?->toIso8601String(),
                'lead' => $run->lead ? [
                    'id' => $run->lead->uuid,
                    'reference' => $run->lead->reference,
                    'contact_name' => $run->lead->contact_name,
                ] : null,
                'context' => (object) ($run->context ?? []),
                'steps' => $run->steps->map(fn (AutomationRunStep $step) => [
                    'position' => $step->position,
                    'type' => $step->type,
                    'status' => $step->status,
                    'attempts' => $step->attempts,
                    'last_error' => $step->last_error,
                    'scheduled_for' => $step->scheduled_for?->toIso8601String(),
                    'executed_at' => $step->executed_at?->toIso8601String(),
                    'output' => (object) ($step->output ?? []),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Fan an event out to every active workflow that listens for it.
     */
    public function dispatch(string $trigger, MarketingLead $lead, array $context = []): int
    {
        $workflows = AutomationWorkflow::query()
            ->with('steps')
            ->where('status', 'active')
            ->where('trigger', $trigger)
            ->get();

        $created = 0;

        foreach ($workflows as $workflow) {
            if (! $this->matchesConditions($workflow, $lead)) {
                continue;
            }

            $eventKey = hash('sha256', implode('|', [
                'automation',
                $workflow->id,
                $trigger,
                $lead->id,
                $context['signature'] ?? 'default',
            ]));

            try {
                DB::transaction(function () use ($workflow, $lead, $trigger, $context, $eventKey): void {
                    $run = AutomationRun::create([
                        'workflow_id' => $workflow->id,
                        'lead_id' => $lead->id,
                        'trigger' => $trigger,
                        'event_key' => $eventKey,
                        'status' => 'queued',
                        'context' => $context,
                        'started_at' => now(),
                    ]);

                    foreach ($workflow->steps as $step) {
                        $run->steps()->create([
                            'step_id' => $step->id,
                            'position' => $step->position,
                            'type' => $step->type,
                            'config' => $step->config ?? [],
                            'status' => 'pending',
                        ]);
                    }
                });
            } catch (QueryException) {
                continue;
            }

            $created++;

            $run = AutomationRun::query()->where('event_key', $eventKey)->first();

            if ($run === null) {
                continue;
            }

            if (! $this->enabled()) {
                $run->forceFill([
                    'status' => 'blocked',
                    'last_error' => self::KILL_SWITCH_MESSAGE,
                    'finished_at' => now(),
                ])->save();

                continue;
            }

            $this->advance($run);
        }

        return $created;
    }

    public function processDue(int $limit = 50): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $runs = AutomationRun::query()
            ->where(function ($query): void {
                $query->where('status', 'queued')
                    ->orWhere(fn ($waiting) => $waiting
                        ->where('status', 'waiting')
                        ->where(fn ($due) => $due->whereNull('resume_at')->orWhere('resume_at', '<=', now())));
            })
            ->orderBy('created_at')
            ->limit(min(max($limit, 1), 200))
            ->get();

        foreach ($runs as $run) {
            $this->advance($run);
        }

        return $runs->count();
    }

    public function advance(AutomationRun $run): AutomationRun
    {
        $run->refresh();

        if (in_array($run->status, ['completed', 'failed', 'blocked'], true)) {
            return $run;
        }

        if (! $this->enabled()) {
            $run->forceFill([
                'status' => 'blocked',
                'last_error' => self::KILL_SWITCH_MESSAGE,
                'finished_at' => now(),
            ])->save();

            return $run->fresh(['steps']);
        }

        $workflow = $run->workflow()->first();

        if ($workflow === null) {
            $run->forceFill(['status' => 'failed', 'last_error' => 'Workflow no longer exists.', 'finished_at' => now()])->save();

            return $run->fresh(['steps']);
        }

        $run->forceFill([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'last_error' => null,
        ])->save();

        $blockedReason = null;
        $pending = $run->steps()->where('status', 'pending')->orderBy('position')->get();

        foreach ($pending as $step) {
            if ($step->scheduled_for !== null && $step->scheduled_for->isFuture()) {
                $run->forceFill(['status' => 'waiting', 'resume_at' => $step->scheduled_for])->save();

                return $run->fresh(['steps']);
            }

            if ($step->type === 'wait') {
                if ($step->scheduled_for === null) {
                    $due = now()->addMinutes(max(1, (int) ($step->config['minutes'] ?? 1)));
                    $step->forceFill(['scheduled_for' => $due])->save();
                    $run->forceFill(['status' => 'waiting', 'resume_at' => $due])->save();

                    return $run->fresh(['steps']);
                }

                $step->forceFill([
                    'status' => 'executed',
                    'executed_at' => now(),
                    'output' => ['minutes' => (int) ($step->config['minutes'] ?? 1)],
                ])->save();

                continue;
            }

            try {
                $output = $this->executeStep($workflow, $run, $step);
            } catch (\Throwable $exception) {
                report($exception);

                $attempts = $step->attempts + 1;
                $message = Str::limit($exception->getMessage(), 500, '');

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $step->forceFill(['status' => 'failed', 'attempts' => $attempts, 'last_error' => $message])->save();
                    $run->forceFill([
                        'status' => 'failed',
                        'attempts' => $run->attempts + 1,
                        'last_error' => $message,
                        'finished_at' => now(),
                    ])->save();

                    return $run->fresh(['steps']);
                }

                $delay = self::BACKOFF_MINUTES[min($attempts - 1, count(self::BACKOFF_MINUTES) - 1)];
                $step->forceFill([
                    'status' => 'pending',
                    'attempts' => $attempts,
                    'last_error' => $message,
                    'scheduled_for' => now()->addMinutes($delay),
                ])->save();
                $run->forceFill([
                    'status' => 'waiting',
                    'attempts' => $run->attempts + 1,
                    'last_error' => $message,
                    'resume_at' => now()->addMinutes($delay),
                ])->save();

                return $run->fresh(['steps']);
            }

            $status = $output['status'] ?? 'executed';

            if ($status === 'blocked') {
                $blockedReason ??= $output['message'] ?? 'Step blocked.';
            }

            $step->forceFill([
                'status' => $status,
                'attempts' => $step->attempts + 1,
                'output' => $output,
                'last_error' => $status === 'blocked' ? ($output['reason'] ?? null) : null,
                'executed_at' => now(),
            ])->save();

            $run->forceFill(['current_position' => $step->position])->save();
        }

        $run->forceFill([
            'status' => $blockedReason !== null ? 'blocked' : 'completed',
            'last_error' => $blockedReason,
            'resume_at' => null,
            'finished_at' => now(),
        ])->save();

        return $run->fresh(['steps']);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeStep(AutomationWorkflow $workflow, AutomationRun $run, AutomationRunStep $step): array
    {
        $run->loadMissing('lead');
        $lead = $run->lead;

        return match ($step->type) {
            'notify_internal' => $this->notifyInternal($workflow, $run, $step, $lead),
            'notify_external' => $this->notifyExternal($workflow, $run, $step, $lead),
            'add_activity' => $this->addActivity($workflow, $run, $step, $lead),
            default => throw new \RuntimeException('Unknown automation step type: '.$step->type),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function notifyInternal(AutomationWorkflow $workflow, AutomationRun $run, AutomationRunStep $step, ?MarketingLead $lead): array
    {
        $config = $step->config ?? [];
        $recipients = ($config['recipients'] ?? 'lead_managers') === 'lead_owner'
            ? collect([$lead?->assignedTo])->filter()
            : $this->leadManagers();

        $title = $this->render($config['title'] ?? 'New lead {reference}', $workflow, $lead);
        $body = $this->render($config['body'] ?? '{contact_name} — {business_name}', $workflow, $lead);

        $count = $this->notifications->notify(
            $recipients,
            'marketing_lead',
            $title,
            $body,
            ['workflow_id' => $workflow->uuid, 'run_id' => $run->uuid, 'lead_id' => $lead?->uuid],
        );

        return ['status' => 'executed', 'channel' => 'in_app', 'recipient_count' => $count];
    }

    /**
     * @return array<string, mixed>
     */
    private function notifyExternal(AutomationWorkflow $workflow, AutomationRun $run, AutomationRunStep $step, ?MarketingLead $lead): array
    {
        $channel = (string) ($step->config['channel'] ?? 'email');

        if ($lead === null) {
            return ['status' => 'blocked', 'channel' => $channel, 'reason' => 'LEAD_NOT_FOUND', 'message' => 'The lead no longer exists, so nothing was sent.', 'sent' => false];
        }

        $consent = MarketingConsent::query()
            ->where('subject_type', 'lead')
            ->where('subject_id', $lead->id)
            ->where('channel', $channel)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $granted = $consent?->status === 'granted' && (bool) $lead->consent_marketing;

        if (! $granted) {
            return [
                'status' => 'blocked',
                'channel' => $channel,
                'reason' => 'CONSENT_MISSING',
                'message' => "The lead has not granted {$channel} marketing consent, so nothing was sent.",
                'sent' => false,
            ];
        }

        return [
            'status' => 'blocked',
            'channel' => $channel,
            'reason' => 'PROVIDER_NOT_APPROVED',
            'message' => self::PROVIDER_MESSAGE,
            'sent' => false,
            'preview' => [
                'title' => $this->render($step->config['title'] ?? 'Next step with BRATHAMATU', $workflow, $lead),
                'body' => $this->render($step->config['body'] ?? '', $workflow, $lead),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addActivity(AutomationWorkflow $workflow, AutomationRun $run, AutomationRunStep $step, ?MarketingLead $lead): array
    {
        if ($lead === null) {
            return ['status' => 'blocked', 'reason' => 'LEAD_NOT_FOUND', 'message' => 'The lead no longer exists.'];
        }

        $body = $this->render($step->config['body'] ?? 'Automation follow-up.', $workflow, $lead);

        $activity = $lead->activities()->create([
            'type' => 'note',
            'body' => $body,
            'metadata' => ['event' => 'automation', 'workflow' => $workflow->name, 'run_id' => $run->uuid],
            'created_at' => now(),
        ]);

        $lead->forceFill(['last_activity_at' => now()])->save();

        return ['status' => 'executed', 'activity_id' => $activity->id];
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function leadManagers()
    {
        return User::query()
            ->whereHas('roles', function ($query): void {
                $query->whereNull('user_roles.tenant_id')
                    ->whereHas('permissions', fn ($permission) => $permission->where('slug', 'leads.manage'));
            })
            ->orderBy('name')
            ->get();
    }

    private function render(string $template, AutomationWorkflow $workflow, ?MarketingLead $lead): string
    {
        return strtr($template, [
            '{reference}' => (string) $lead?->reference,
            '{contact_name}' => (string) $lead?->contact_name,
            '{business_name}' => (string) ($lead?->business_name ?: 'your business'),
            '{email}' => (string) $lead?->email,
            '{phone}' => (string) $lead?->phone,
            '{type}' => (string) $lead?->type,
            '{status}' => (string) $lead?->status,
            '{workflow}' => $workflow->name,
        ]);
    }

    private function matchesConditions(AutomationWorkflow $workflow, MarketingLead $lead): bool
    {
        $conditions = $workflow->conditions ?? [];

        $types = array_values(array_filter((array) ($conditions['lead_type'] ?? [])));
        if ($types !== [] && ! in_array($lead->type, $types, true)) {
            return false;
        }

        if (! empty($conditions['source']) && $lead->source !== $conditions['source']) {
            return false;
        }

        if (! empty($conditions['utm_campaign']) && Str::lower((string) $lead->utm_campaign) !== Str::lower((string) $conditions['utm_campaign'])) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>|null
     */
    private function conditions(array $conditions): ?array
    {
        $clean = array_filter([
            'lead_type' => array_values(array_filter((array) ($conditions['lead_type'] ?? []))),
            'source' => trim((string) ($conditions['source'] ?? '')),
            'utm_campaign' => trim((string) ($conditions['utm_campaign'] ?? '')),
        ], fn ($value) => $value !== '' && $value !== []);

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function replaceSteps(AutomationWorkflow $workflow, array $steps): void
    {
        $workflow->steps()->delete();

        foreach (array_values($steps) as $index => $step) {
            $workflow->steps()->create([
                'position' => $index + 1,
                'type' => $step['type'],
                'config' => $step['config'] ?? [],
            ]);
        }
    }
}
