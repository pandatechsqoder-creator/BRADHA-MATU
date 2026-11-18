<?php

namespace App\Services\Operations;

use App\Models\JobChecklist;
use App\Models\JobChecklistItem;
use App\Models\JobProcedure;
use App\Models\JobProcedureStep;
use App\Models\JobProgressEvent;
use App\Models\ProcedureTemplate;
use App\Models\ProcedureTemplateVersion;
use App\Models\StocktakeJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProcedureService
{
    private const LOCKED_JOB_STATUSES = [
        'count_submitted', 'quality_review', 'recount_required', 'awaiting_tenant_review',
        'tenant_correction_requested', 'disputed', 'completion_confirmed', 'payout_pending_authorisation',
        'payout_processing', 'payout_completed', 'closed', 'cancelled', 'refunded', 'rejected',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function templates(?string $search = null): array
    {
        return ProcedureTemplate::query()
            ->with(['currentVersion', 'versions'])
            ->when($search, fn ($query, $term) => $query->where(function ($inner) use ($term): void {
                $inner->where('name', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%");
            }))
            ->orderBy('name')
            ->get()
            ->map(fn (ProcedureTemplate $template) => $this->templatePayload($template))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function templatePayload(ProcedureTemplate $template, bool $withVersions = true): array
    {
        $template->loadMissing(['currentVersion', 'versions']);

        return [
            'id' => $template->uuid,
            'name' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
            'category' => $template->category,
            'status' => $template->status,
            'scope' => $template->tenant_id === null ? 'platform' : 'tenant',
            'current_version' => $template->currentVersion ? $this->versionPayload($template->currentVersion, true) : null,
            'versions' => $withVersions
                ? $template->versions->map(fn (ProcedureTemplateVersion $version) => $this->versionPayload($version, false))->values()->all()
                : [],
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function versionPayload(ProcedureTemplateVersion $version, bool $withSteps = true): array
    {
        return [
            'id' => $version->uuid,
            'version' => (int) $version->version,
            'title' => $version->title,
            'summary' => $version->summary,
            'steps' => $withSteps ? $this->normalizeSteps($version->steps ?? []) : count($version->steps ?? []),
            'checklists' => $version->checklists ?? [],
            'require_acknowledgement' => (bool) $version->require_acknowledgement,
            'status' => $version->status,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    public function createTemplate(array $data, User $actor): ProcedureTemplate
    {
        return DB::transaction(function () use ($data, $actor): ProcedureTemplate {
            $template = ProcedureTemplate::create([
                'tenant_id' => null,
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['name']),
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? 'stocktake',
                'status' => 'active',
                'created_by' => $actor->id,
            ]);

            $version = $this->createVersion($template, $data, $actor, 1);
            $template->update(['current_version_id' => $version->id]);

            $this->audit->log('sop.template.created', $template, null, null, [
                'name' => $template->name,
                'version' => 1,
                'steps' => count($data['steps']),
            ]);

            return $template->fresh(['currentVersion', 'versions']);
        });
    }

    public function updateTemplate(ProcedureTemplate $template, array $data, User $actor): ProcedureTemplate
    {
        $before = $template->only(['name', 'description', 'category', 'status']);
        $template->fill(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($value) => $value !== null));
        $template->save();

        $this->audit->log('sop.template.updated', $template, null, $before, $template->only(['name', 'description', 'category', 'status']));

        return $template->fresh(['currentVersion', 'versions']);
    }

    public function publishVersion(ProcedureTemplate $template, array $data, User $actor): ProcedureTemplate
    {
        return DB::transaction(function () use ($template, $data, $actor): ProcedureTemplate {
            $template->refresh();
            $current = $template->currentVersion;

            $payload = [
                'title' => $data['title'] ?? $current?->title ?? $template->name,
                'summary' => $data['summary'] ?? $current?->summary,
                'steps' => $data['steps'] ?? $current?->steps ?? [],
                'checklists' => $data['checklists'] ?? $current?->checklists ?? [],
                'require_acknowledgement' => $data['require_acknowledgement'] ?? $current?->require_acknowledgement ?? true,
            ];

            if (count($this->normalizeSteps($payload['steps'])) === 0) {
                throw ValidationException::withMessages(['steps' => ['A procedure version needs at least one step.']]);
            }

            $nextVersion = ((int) $template->versions()->max('version')) + 1;

            $template->versions()->where('status', 'published')->update(['status' => 'superseded']);
            $version = $this->createVersion($template, $payload, $actor, $nextVersion);

            $template->update(['status' => 'active', 'current_version_id' => $version->id]);

            $this->audit->log('sop.template.version_published', $template, null, ['version' => $nextVersion - 1], [
                'version' => $nextVersion,
                'steps' => count($this->normalizeSteps($payload['steps'])),
            ]);

            return $template->fresh(['currentVersion', 'versions']);
        });
    }

    public function applyToJob(StocktakeJob $job, array $data, User $actor): JobProcedure
    {
        return DB::transaction(function () use ($job, $data, $actor): JobProcedure {
            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($job->id)->lockForUpdate()->firstOrFail();

            if (in_array($job->status, self::LOCKED_JOB_STATUSES, true) || in_array($job->status, ['in_progress', 'paused'], true)) {
                throw ValidationException::withMessages(['job' => ['The SOP cannot be changed once counting has started.']]);
            }

            $template = null;
            $version = null;

            if (! empty($data['template_id'])) {
                $template = ProcedureTemplate::where('uuid', $data['template_id'])->firstOrFail();

                if ($template->tenant_id !== null && $template->tenant_id !== $job->tenant_id) {
                    abort(404);
                }

                $version = ! empty($data['version_id'])
                    ? $template->versions()->where('uuid', $data['version_id'])->firstOrFail()
                    : $template->currentVersion;

                if ($version === null) {
                    throw ValidationException::withMessages(['version_id' => ['This procedure template has no published version.']]);
                }

                if ($version->status !== 'published') {
                    throw ValidationException::withMessages(['version_id' => ['Only published procedure versions can be applied.']]);
                }

                $title = $version->title;
                $summary = $version->summary;
                $steps = $version->steps ?? [];
                $checklists = $version->checklists ?? [];
                $requireAcknowledgement = (bool) $version->require_acknowledgement;
            } else {
                $title = $data['title'];
                $summary = $data['summary'] ?? null;
                $steps = $data['steps'] ?? [];
                $checklists = $data['checklists'] ?? [];
                $requireAcknowledgement = (bool) ($data['require_acknowledgement'] ?? true);
            }

            $steps = $this->normalizeSteps($steps);
            if (count($steps) === 0) {
                throw ValidationException::withMessages(['steps' => ['A job SOP needs at least one step.']]);
            }

            JobProcedure::where('job_id', $job->id)->where('status', 'active')->update(['status' => 'superseded']);
            JobChecklist::where('job_id', $job->id)->delete();

            $procedure = JobProcedure::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'template_id' => $template?->id,
                'template_version_id' => $version?->id,
                'version' => ((int) JobProcedure::where('job_id', $job->id)->max('version')) + 1,
                'title' => $title,
                'summary' => $summary,
                'steps' => $steps,
                'require_acknowledgement' => $requireAcknowledgement,
                'status' => 'active',
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            foreach ($steps as $index => $step) {
                JobProcedureStep::create([
                    'job_procedure_id' => $procedure->id,
                    'position' => $index + 1,
                    ...$step,
                ]);
            }

            $this->syncChecklists($job, $procedure, $checklists);

            JobProgressEvent::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'stage' => 'procedure_assigned',
                'percent' => 0,
                'message' => 'SOP v'.$procedure->version.' assigned: '.$procedure->title,
                'actor_id' => $actor->id,
                'actor_type' => 'platform',
                'occurred_at' => now(),
            ]);

            $this->audit->log('sop.job.assigned', $procedure, null, null, [
                'job' => $job->reference,
                'template' => $template?->slug,
                'version' => $procedure->version,
                'require_acknowledgement' => $requireAcknowledgement,
            ], $job->tenant_id);

            return $procedure->fresh(['procedureSteps', 'checklists.items', 'template', 'templateVersion']);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jobProcedurePayload(StocktakeJob $job): ?array
    {
        $procedure = $this->activeProcedure($job);
        if ($procedure === null) {
            return null;
        }

        $procedure->loadMissing(['procedureSteps', 'checklists.items', 'template']);

        $acknowledged = $procedure->acknowledgements()->count();

        return [
            'id' => $procedure->uuid,
            'template_id' => $procedure->template?->uuid,
            'template_name' => $procedure->template?->name,
            'version' => (int) $procedure->version,
            'title' => $procedure->title,
            'summary' => $procedure->summary,
            'require_acknowledgement' => (bool) $procedure->require_acknowledgement,
            'status' => $procedure->status,
            'assigned_at' => $procedure->assigned_at?->toIso8601String(),
            'acknowledgements' => $acknowledged,
            'steps' => $procedure->procedureSteps->map(fn (JobProcedureStep $step) => [
                'id' => $step->id,
                'position' => (int) $step->position,
                'title' => $step->title,
                'instructions' => $step->instructions,
                'required' => (bool) $step->required,
                'evidence_required' => (bool) $step->evidence_required,
            ])->values()->all(),
            'checklists' => $procedure->checklists->map(fn (JobChecklist $checklist) => [
                'id' => $checklist->uuid,
                'name' => $checklist->name,
                'kind' => $checklist->kind,
                'required' => (bool) $checklist->required,
                'items_total' => $checklist->items->count(),
                'items_required' => $checklist->items->where('required', true)->count(),
            ])->values()->all(),
        ];
    }

    public function activeProcedure(StocktakeJob $job): ?JobProcedure
    {
        return JobProcedure::where('job_id', $job->id)
            ->where('status', 'active')
            ->with(['procedureSteps', 'checklists.items', 'template'])
            ->latest('id')
            ->first();
    }

    private function createVersion(ProcedureTemplate $template, array $data, User $actor, int $version): ProcedureTemplateVersion
    {
        $steps = $this->normalizeSteps($data['steps'] ?? []);
        if (count($steps) === 0) {
            throw ValidationException::withMessages(['steps' => ['A procedure version needs at least one step.']]);
        }

        return ProcedureTemplateVersion::create([
            'template_id' => $template->id,
            'version' => $version,
            'title' => $data['title'] ?? $template->name,
            'summary' => $data['summary'] ?? null,
            'steps' => $steps,
            'checklists' => $this->normalizeChecklists($data['checklists'] ?? []),
            'require_acknowledgement' => (bool) ($data['require_acknowledgement'] ?? true),
            'status' => 'published',
            'published_at' => now(),
            'published_by' => $actor->id,
        ]);
    }

    private function syncChecklists(StocktakeJob $job, JobProcedure $procedure, array $checklists): void
    {
        foreach ($this->normalizeChecklists($checklists) as $position => $checklist) {
            $row = JobChecklist::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'job_procedure_id' => $procedure->id,
                'name' => $checklist['name'],
                'kind' => $checklist['kind'],
                'required' => $checklist['required'],
                'status' => 'pending',
            ]);

            foreach ($checklist['items'] as $itemPosition => $item) {
                JobChecklistItem::create([
                    'checklist_id' => $row->id,
                    'position' => $itemPosition + 1,
                    'label' => $item['label'],
                    'instructions' => $item['instructions'] ?? null,
                    'response_type' => $item['response_type'],
                    'required' => $item['required'],
                    'options' => $item['options'] ?? null,
                ]);
            }
        }
    }

    /**
     * @return array<int, array{title: string, instructions: ?string, required: bool, evidence_required: bool}>
     */
    private function normalizeSteps(array $steps): array
    {
        return collect($steps)
            ->filter(fn ($step) => is_array($step) && trim((string) ($step['title'] ?? '')) !== '')
            ->map(fn (array $step) => [
                'title' => trim((string) $step['title']),
                'instructions' => isset($step['instructions']) ? trim((string) $step['instructions']) : null,
                'required' => (bool) ($step['required'] ?? true),
                'evidence_required' => (bool) ($step['evidence_required'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: string, kind: string, required: bool, items: array<int, array<string, mixed>>}>
     */
    private function normalizeChecklists(array $checklists): array
    {
        if ($checklists === []) {
            return [];
        }

        if (isset($checklists['items']) || isset($checklists['name'])) {
            $checklists = [$checklists];
        }

        return collect($checklists)
            ->filter(fn ($checklist) => is_array($checklist))
            ->map(function (array $checklist, int $index): array {
                $items = collect($checklist['items'] ?? [])
                    ->filter(fn ($item) => is_array($item) && trim((string) ($item['label'] ?? '')) !== '')
                    ->map(fn (array $item) => [
                        'label' => trim((string) $item['label']),
                        'instructions' => isset($item['instructions']) ? trim((string) $item['instructions']) : null,
                        'response_type' => in_array($item['response_type'] ?? 'boolean', ['boolean', 'text', 'select'], true)
                            ? ($item['response_type'] ?? 'boolean')
                            : 'boolean',
                        'required' => (bool) ($item['required'] ?? true),
                        'options' => $item['options'] ?? null,
                    ])
                    ->values()
                    ->all();

                return [
                    'name' => trim((string) ($checklist['name'] ?? 'Checklist '.($index + 1))),
                    'kind' => in_array($checklist['kind'] ?? 'pre_start', ['pre_start', 'site', 'close_out'], true)
                        ? ($checklist['kind'] ?? 'pre_start')
                        : 'pre_start',
                    'required' => (bool) ($checklist['required'] ?? true),
                    'items' => $items,
                ];
            })
            ->filter(fn (array $checklist) => count($checklist['items']) > 0)
            ->values()
            ->all();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'procedure';
        $slug = $base;
        $suffix = 2;

        while (ProcedureTemplate::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
