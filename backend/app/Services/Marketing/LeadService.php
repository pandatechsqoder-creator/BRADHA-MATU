<?php

namespace App\Services\Marketing;

use App\Models\Marketing\LeadActivity;
use App\Models\Marketing\LeadAssignment;
use App\Models\Marketing\LeadExport;
use App\Models\Marketing\MarketingConsent;
use App\Models\Marketing\MarketingLead;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LeadService
{
    public const FORM_TYPES = [
        'quote' => 'quote',
        'pos-demo' => 'pos_demo',
        'contact' => 'contact',
        'callback' => 'callback',
        'partnership' => 'partnership',
    ];

    public const ACTIVITY_TYPES = ['note', 'call', 'email', 'sms', 'whatsapp', 'status_change', 'assignment', 'consent'];

    public const CONSENT_POLICY_VERSION = '2026-11-16';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly AnalyticsService $analytics,
        private readonly AutomationService $automation,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $type): array
    {
        $rules = [
            'business_name' => ['nullable', 'string', 'max:190'],
            'contact_name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'industry' => ['nullable', 'string', 'max:120'],
            'town' => ['nullable', 'string', 'max:120'],
            'county' => ['nullable', 'string', 'max:120'],
            'branches_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'catalogue_size' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'service_requested' => ['nullable', 'string', 'max:120'],
            'stocktake_frequency' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:5000'],
            'consent_marketing' => ['sometimes', 'boolean'],
            'consent_transactional' => ['accepted'],
            'policy_version' => ['nullable', 'string', 'max:40'],
            'submission_token' => ['nullable', 'string', 'max:64'],
            'utm_source' => ['nullable', 'string', 'max:190'],
            'utm_medium' => ['nullable', 'string', 'max:190'],
            'utm_campaign' => ['nullable', 'string', 'max:190'],
            'utm_term' => ['nullable', 'string', 'max:190'],
            'utm_content' => ['nullable', 'string', 'max:190'],
            'landing_page' => ['nullable', 'string', 'max:500'],
            'referrer' => ['nullable', 'string', 'max:2000'],
            'form_token' => ['nullable', 'string', 'max:1000'],
        ];

        if ($type === 'quote' || $type === 'pos_demo' || $type === 'partnership') {
            $rules['business_name'] = ['required', 'string', 'max:190'];
        }
        if ($type === 'contact') {
            $rules['message'] = ['required', 'string', 'max:5000'];
        }
        if ($type === 'callback') {
            $rules['phone'] = ['required', 'string', 'max:40'];
        }
        if ($type === 'partnership') {
            $rules['message'] = ['required', 'string', 'max:5000'];
        }

        return $rules;
    }

    /**
     * Capture a public form submission. Returns [lead, created, spam].
     *
     * @param  array<string, mixed>  $data
     * @return array{lead: MarketingLead|null, created: bool, spam: bool}
     */
    public function capture(string $type, array $data, Request $request, ?string $idempotencyKey = null): array
    {
        if ((string) $request->input('website', '') !== '') {
            return ['lead' => null, 'created' => false, 'spam' => true];
        }

        $this->assertHumanTiming($type, $data['form_token'] ?? null);

        $token = $this->submissionToken($type, $data, $idempotencyKey);
        $existing = MarketingLead::withTrashed()->where('submission_token', $token)->first();
        if ($existing !== null) {
            return ['lead' => $existing, 'created' => false, 'spam' => false];
        }

        $consentMarketing = (bool) ($data['consent_marketing'] ?? false);
        $policyVersion = $data['policy_version'] ?? self::CONSENT_POLICY_VERSION;
        $now = now();

        $attributes = [
            'reference' => $this->nextReference(),
            'submission_token' => $token,
            'type' => $type,
            'status' => 'new',
            'business_name' => $data['business_name'] ?? null,
            'contact_name' => $data['contact_name'],
            'email' => isset($data['email']) ? Str::lower(trim($data['email'])) : null,
            'phone' => $data['phone'] ?? null,
            'industry' => $data['industry'] ?? null,
            'town' => $data['town'] ?? null,
            'county' => $data['county'] ?? null,
            'branches_count' => $data['branches_count'] ?? null,
            'catalogue_size' => $data['catalogue_size'] ?? null,
            'service_requested' => $data['service_requested'] ?? null,
            'stocktake_frequency' => $data['stocktake_frequency'] ?? null,
            'message' => $data['message'] ?? null,
            'source' => 'website',
            'utm_source' => $data['utm_source'] ?? $request->query('utm_source'),
            'utm_medium' => $data['utm_medium'] ?? $request->query('utm_medium'),
            'utm_campaign' => $data['utm_campaign'] ?? $request->query('utm_campaign'),
            'utm_term' => $data['utm_term'] ?? $request->query('utm_term'),
            'utm_content' => $data['utm_content'] ?? $request->query('utm_content'),
            'landing_page' => $data['landing_page'] ?? $request->headers->get('referer'),
            'referrer' => $data['referrer'] ?? $request->headers->get('referer'),
            'consent_marketing' => $consentMarketing,
            'consent_transactional' => (bool) ($data['consent_transactional'] ?? false),
            'consent_ip' => $request->ip(),
            'consent_at' => $now,
            'metadata' => [
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'form' => $type,
            ],
            'last_activity_at' => $now,
        ];

        try {
            [$lead, $created] = DB::transaction(function () use ($attributes, $type, $consentMarketing, $policyVersion, $request, $now): array {
                $lead = MarketingLead::create($attributes);

                $lead->activities()->create([
                    'type' => 'note',
                    'body' => 'Lead captured from the '.$this->typeLabel($type).' form.',
                    'metadata' => ['event' => 'form_submitted', 'form' => $type],
                    'created_at' => $now,
                ]);

                $this->recordConsents($lead, [
                    'marketing' => $consentMarketing,
                    'email' => true,
                ], $request, $policyVersion);

                return [$lead, true];
            });
        } catch (QueryException $exception) {
            $lead = MarketingLead::withTrashed()->where('submission_token', $token)->first();
            if ($lead === null) {
                throw $exception;
            }
            $created = false;
        }

        if ($created) {
            $this->audit->log('marketing.lead.captured', $lead, null, null, [
                'reference' => $lead->reference,
                'type' => $lead->type,
                'source' => $lead->source,
                'utm_campaign' => $lead->utm_campaign,
            ]);

            $landing = $lead->landing_page ?? $lead->referrer;
            $path = is_string($landing) && $landing !== '' ? parse_url($landing, PHP_URL_PATH) : null;

            $this->analytics->recordConversion(
                AnalyticsService::CONVERSIONS[$type] ?? $type.'_submission',
                $lead,
                $lead->utm_campaign,
                ['form' => $type, 'path' => $path, 'reference' => $lead->reference],
            );

            $this->dispatchAutomation('lead.created', $lead, ['signature' => 'created', 'form' => $type]);
        }

        return ['lead' => $lead, 'created' => $created, 'spam' => false];
    }

    /**
     * @param  array<string, bool>  $channels
     */
    private function recordConsents(MarketingLead $lead, array $channels, Request $request, string $policyVersion): void
    {
        foreach ($channels as $channel => $granted) {
            MarketingConsent::create([
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
                'channel' => $channel,
                'status' => $granted ? 'granted' : 'withdrawn',
                'source' => 'website_form',
                'policy_version' => $policyVersion,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function list(array $filters, User $user): array
    {
        $query = $this->applyFilters(MarketingLead::query()->with(['assignedTo:id,name,email']), $filters);

        $leads = $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $maskPii = ! $user->hasPermission('leads.export');

        return [
            'leads' => collect($leads->items())
                ->map(fn (MarketingLead $lead) => $this->summary($lead, $maskPii))
                ->all(),
            'pagination' => [
                'page' => $leads->currentPage(),
                'per_page' => $leads->perPage(),
                'total' => $leads->total(),
                'last_page' => $leads->lastPage(),
            ],
            'status_counts' => MarketingLead::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all(),
            'type_counts' => MarketingLead::query()
                ->selectRaw('type, count(*) as aggregate')
                ->groupBy('type')
                ->pluck('aggregate', 'type')
                ->all(),
            'pii_masked' => $maskPii,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($where) => $where
                ->where('reference', 'like', $search)
                ->orWhere('business_name', 'like', $search)
                ->orWhere('contact_name', 'like', $search)
                ->orWhere('email', 'like', $search)
                ->orWhere('phone', 'like', $search));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['assignee'])) {
            if ($filters['assignee'] === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->whereHas('assignedTo', fn ($q) => $q->where('uuid', $filters['assignee']));
            }
        }
        if (! empty($filters['campaign'])) {
            $query->where('utm_campaign', $filters['campaign']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(MarketingLead $lead, bool $maskPii = false): array
    {
        return [
            'id' => $lead->uuid,
            'reference' => $lead->reference,
            'type' => $lead->type,
            'status' => $lead->status,
            'business_name' => $lead->business_name,
            'contact_name' => $lead->contact_name,
            'email' => $maskPii ? $this->maskEmail($lead->email) : $lead->email,
            'phone' => $maskPii ? $this->maskPhone($lead->phone) : $lead->phone,
            'town' => $lead->town,
            'county' => $lead->county,
            'service_requested' => $lead->service_requested,
            'source' => $lead->source,
            'utm_source' => $lead->utm_source,
            'utm_medium' => $lead->utm_medium,
            'utm_campaign' => $lead->utm_campaign,
            'assigned_to' => $lead->assignedTo ? [
                'id' => $lead->assignedTo->uuid,
                'name' => $lead->assignedTo->name,
            ] : null,
            'last_activity_at' => $lead->last_activity_at?->toIso8601String(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(MarketingLead $lead): array
    {
        $lead->load([
            'assignedTo:id,uuid,name,email',
            'owner:id,uuid,name,email',
            'activities.author:id,uuid,name',
            'assignments.assignee:id,uuid,name',
        ]);

        $consents = MarketingConsent::query()
            ->where('subject_type', 'lead')
            ->where('subject_id', $lead->id)
            ->orderByDesc('occurred_at')
            ->get()
            ->map(fn (MarketingConsent $consent) => [
                'channel' => $consent->channel,
                'status' => $consent->status,
                'source' => $consent->source,
                'policy_version' => $consent->policy_version,
                'occurred_at' => $consent->occurred_at?->toIso8601String(),
            ])
            ->all();

        return array_merge($this->summary($lead), [
            'business_name' => $lead->business_name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'industry' => $lead->industry,
            'branches_count' => $lead->branches_count,
            'catalogue_size' => $lead->catalogue_size,
            'stocktake_frequency' => $lead->stocktake_frequency,
            'message' => $lead->message,
            'utm_term' => $lead->utm_term,
            'utm_content' => $lead->utm_content,
            'landing_page' => $lead->landing_page,
            'referrer' => $lead->referrer,
            'consent_marketing' => $lead->consent_marketing,
            'consent_transactional' => $lead->consent_transactional,
            'consent_at' => $lead->consent_at?->toIso8601String(),
            'owner' => $lead->owner ? ['id' => $lead->owner->uuid, 'name' => $lead->owner->name] : null,
            'converted_at' => $lead->converted_at?->toIso8601String(),
            'activities' => $lead->activities->map(fn (LeadActivity $activity) => [
                'id' => $activity->id,
                'type' => $activity->type,
                'body' => $activity->body,
                'metadata' => $activity->metadata ?? [],
                'author' => $activity->author ? ['id' => $activity->author->uuid, 'name' => $activity->author->name] : null,
                'created_at' => $activity->created_at?->toIso8601String(),
            ])->values()->all(),
            'assignments' => $lead->assignments->map(fn (LeadAssignment $assignment) => [
                'id' => $assignment->id,
                'assigned_to' => $assignment->assignee ? ['id' => $assignment->assignee->uuid, 'name' => $assignment->assignee->name] : null,
                'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                'unassigned_at' => $assignment->unassigned_at?->toIso8601String(),
                'reason' => $assignment->reason,
            ])->values()->all(),
            'consents' => $consents,
        ]);
    }

    public function updateStatus(MarketingLead $lead, string $status, User $user): MarketingLead
    {
        $before = $lead->status;
        if ($before === $status) {
            return $lead;
        }

        $lead->forceFill([
            'status' => $status,
            'converted_at' => $status === 'won' ? ($lead->converted_at ?? now()) : $lead->converted_at,
            'last_activity_at' => now(),
        ])->save();

        $lead->activities()->create([
            'type' => 'status_change',
            'body' => "Status changed from {$before} to {$status}.",
            'metadata' => ['from' => $before, 'to' => $status],
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $this->audit->log('marketing.lead.status_changed', $lead, null, ['status' => $before], ['status' => $status]);

        if ($lead->assigned_to !== null && $lead->assigned_to !== $user->id) {
            $assignee = $lead->assignedTo;
            if ($assignee !== null) {
                $this->notifications->notify(
                    $assignee,
                    'marketing_lead',
                    "Lead {$lead->reference} moved to {$status}",
                    $lead->contact_name,
                    ['lead_id' => $lead->uuid, 'status' => $status],
                );
            }
        }

        $this->dispatchAutomation('lead.status_changed', $lead, [
            'signature' => "status:{$before}>{$status}",
            'from' => $before,
            'to' => $status,
        ]);

        return $lead->fresh(['assignedTo']);
    }

    public function assign(MarketingLead $lead, User $assignee, User $actor, ?string $reason = null): MarketingLead
    {
        $previous = $lead->assigned_to;

        DB::transaction(function () use ($lead, $assignee, $actor, $reason): void {
            LeadAssignment::query()
                ->where('lead_id', $lead->id)
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            LeadAssignment::create([
                'lead_id' => $lead->id,
                'assigned_to' => $assignee->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
                'reason' => $reason,
            ]);

            $lead->forceFill([
                'assigned_to' => $assignee->id,
                'status' => $lead->status === 'new' ? 'assigned' : $lead->status,
                'last_activity_at' => now(),
            ])->save();

            $lead->activities()->create([
                'type' => 'assignment',
                'body' => "Assigned to {$assignee->name}.",
                'metadata' => ['assigned_to' => $assignee->uuid, 'reason' => $reason],
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        });

        $this->audit->log('marketing.lead.assigned', $lead, $reason, ['assigned_to' => $previous], ['assigned_to' => $assignee->id]);

        $this->notifications->notify(
            $assignee,
            'marketing_lead',
            "New lead assigned: {$lead->reference}",
            trim(($lead->business_name ? $lead->business_name.' — ' : '').$lead->contact_name),
            ['lead_id' => $lead->uuid],
        );

        $this->dispatchAutomation('lead.assigned', $lead, [
            'signature' => 'assigned:'.$assignee->id,
            'assigned_to' => $assignee->uuid,
        ]);

        return $lead->fresh(['assignedTo']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addActivity(MarketingLead $lead, array $data, User $user): LeadActivity
    {
        $activity = $lead->activities()->create([
            'type' => $data['type'],
            'body' => $data['body'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $lead->forceFill(['last_activity_at' => now()])->save();

        return $activity;
    }

    public function recordConsent(MarketingLead $lead, string $channel, string $status, User $user): MarketingConsent
    {
        $consent = MarketingConsent::create([
            'subject_type' => 'lead',
            'subject_id' => $lead->id,
            'channel' => $channel,
            'status' => $status,
            'source' => 'crm',
            'policy_version' => self::CONSENT_POLICY_VERSION,
            'occurred_at' => now(),
        ]);

        $lead->forceFill([
            'consent_marketing' => $channel === 'marketing' ? $status === 'granted' : $lead->consent_marketing,
            'last_activity_at' => now(),
        ])->save();

        $lead->activities()->create([
            'type' => 'consent',
            'body' => "Consent {$status} for {$channel}.",
            'metadata' => ['channel' => $channel, 'status' => $status],
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        $this->audit->log('marketing.lead.consent_recorded', $lead, null, null, [
            'channel' => $channel,
            'status' => $status,
        ]);

        return $consent;
    }

    /**
     * @return array<string, mixed>
     */
    public function export(array $filters, User $user): StreamedResponse
    {
        $query = $this->applyFilters(MarketingLead::query()->with(['assignedTo:id,name']), $filters);

        $leads = $query->orderByDesc('created_at')->limit(5000)->get();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'Reference', 'Created', 'Type', 'Status', 'Business', 'Contact', 'Email', 'Phone',
            'Industry', 'Town', 'County', 'Branches', 'Catalogue size', 'Service requested',
            'Frequency', 'Message', 'UTM source', 'UTM medium', 'UTM campaign', 'Assigned to',
            'Consent marketing', 'Consent transactional',
        ]);
        foreach ($leads as $lead) {
            fputcsv($handle, [
                $lead->reference,
                $lead->created_at?->toIso8601String(),
                $lead->type,
                $lead->status,
                $lead->business_name,
                $lead->contact_name,
                $lead->email,
                $lead->phone,
                $lead->industry,
                $lead->town,
                $lead->county,
                $lead->branches_count,
                $lead->catalogue_size,
                $lead->service_requested,
                $lead->stocktake_frequency,
                $lead->message,
                $lead->utm_source,
                $lead->utm_medium,
                $lead->utm_campaign,
                $lead->assignedTo?->name,
                $lead->consent_marketing ? 'yes' : 'no',
                $lead->consent_transactional ? 'yes' : 'no',
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        LeadExport::create([
            'requested_by' => $user->id,
            'filters' => $filters,
            'row_count' => $leads->count(),
            'checksum' => hash('sha256', (string) $csv),
            'created_at' => now(),
        ]);

        $this->audit->log('marketing.leads.exported', null, null, null, [
            'rows' => $leads->count(),
            'filters' => $filters,
        ]);

        $filename = 'leads-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($csv): void {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatchAutomation(string $trigger, MarketingLead $lead, array $context): void
    {
        try {
            $this->automation->dispatch($trigger, $lead, $context);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function assertHumanTiming(string $type, ?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['form_token' => 'This form submission could not be verified. Please reload the page and try again.']);
        }

        $startedAt = (int) ($payload['ts'] ?? 0);
        $elapsed = time() - $startedAt;

        if (($payload['type'] ?? null) !== $type || $elapsed < 2 || $elapsed > 7200) {
            throw ValidationException::withMessages(['form_token' => 'This form submission could not be verified. Please reload the page and try again.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function submissionToken(string $type, array $data, ?string $idempotencyKey): string
    {
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            return hash('sha256', 'lead:'.$type.':'.$idempotencyKey);
        }
        if (! empty($data['submission_token'])) {
            return hash('sha256', 'lead:'.$type.':'.$data['submission_token']);
        }

        return hash('sha256', implode('|', [
            'lead',
            $type,
            Str::lower((string) ($data['email'] ?? '')),
            preg_replace('/\s+/', '', (string) ($data['phone'] ?? '')),
            Str::lower(trim((string) ($data['message'] ?? ''))),
            now()->toDateString(),
        ]));
    }

    private function nextReference(): string
    {
        do {
            $reference = 'LD-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (MarketingLead::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'quote' => 'quotation',
            'pos_demo' => 'POS demonstration',
            default => $type,
        };
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1).str_repeat('*', max(1, strlen($local) - 1)).'@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) <= 3 ? '***' : '***'.substr($digits, -3);
    }
}
