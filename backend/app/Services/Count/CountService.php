<?php

namespace App\Services\Count;

use App\Models\CountEntry;
use App\Models\CountEntryRevision;
use App\Models\CountException;
use App\Models\CountSession;
use App\Models\CountSubmissionLock;
use App\Models\JobAssignment;
use App\Models\ProductVariant;
use App\Models\StocktakeJob;
use App\Models\UnknownProductReport;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Operations\JobOperationsService;
use App\Services\Stocktake\StocktakeWorkflow;
use App\Services\Workforce\WorkforceProfileService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CountService
{
    public function __construct(
        private readonly JobOperationsService $operations,
        private readonly StocktakeWorkflow $workflow,
        private readonly WorkforceProfileService $profiles,
    ) {}

    /**
     * Worker count workspace: readiness gate + assigned product scope + any open session.
     * Blind mode by default — no expected quantity or valuation is ever returned.
     *
     * @return array<string, mixed>
     */
    public function workspace(JobAssignment $assignment, User $worker): array
    {
        $this->assertOwned($assignment, $worker);
        $job = $this->jobForAssignment($assignment);

        $session = CountSession::where('assignment_id', $assignment->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        // Non-throwing readiness so the UI can render gate blockers before the worker is ready.
        $readiness = $this->operations->workerPayload($assignment)['readiness'] ?? [];

        return [
            'job' => $this->jobSummary($job),
            'readiness' => $readiness,
            'mode' => $this->modeFor($job),
            'scope' => $this->scopePayload($job),
            'session' => $session ? $this->sessionPayload($session, $job) : null,
        ];
    }

    /**
     * Start a count session. Pins the job catalogue version and a frozen scope snapshot.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function startSession(JobAssignment $assignment, array $data, User $worker): array
    {
        $job = $this->ownedJob($assignment, $worker, requireReady: true);

        return DB::transaction(function () use ($assignment, $job, $data, $worker): array {
            $existing = CountSession::where('assignment_id', $assignment->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $this->sessionPayload($existing->fresh(), $job);
            }

            $session = CountSession::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'assignment_id' => $assignment->id,
                'catalogue_version_id' => $job->catalogue_version_id,
                'mode' => $this->modeFor($job),
                'status' => 'active',
                'scope' => $this->frozenScope($job),
                'device' => $data['device'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'started_by' => $worker->id,
                'started_at' => now(),
                'created_by' => $worker->id,
            ]);

            return $this->sessionPayload($session, $job);
        });
    }

    /**
     * Session detail with entries and scope.
     *
     * @return array<string, mixed>
     */
    public function session(JobAssignment $assignment, CountSession $session, User $worker): array
    {
        $this->assertSessionOwned($assignment, $session, $worker, requireActive: false);
        $job = $this->jobForAssignment($assignment);

        return $this->sessionPayload($session->loadMissing(['entries', 'exceptions', 'unknownProducts']), $job);
    }

    /**
     * Upsert a count entry (autosave). Idempotent by key. Validates the variant is in scope
     * and the unit is catalogue-approved. Never writes expected stock.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function upsertEntry(JobAssignment $assignment, CountSession $session, array $data, User $worker): array
    {
        $this->assertSessionOwned($assignment, $session, $worker, requireActive: true);

        $data = validator($data, [
            'idempotency_key' => ['required', 'string', 'max:200'],
            'variant_id' => ['required', 'ulid'],
            'stock_location_id' => ['nullable', 'ulid'],
            'raw_quantity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'raw_unit_id' => ['required', 'ulid'],
            'observations' => ['nullable', 'array'],
            'observations.*' => ['string', 'in:good,damaged,expired,quarantined,missing_label,inaccessible,unknown'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'captured_at' => ['nullable', 'date'],
        ])->validate();

        if ((float) $data['raw_quantity'] < 0) {
            throw ValidationException::withMessages(['raw_quantity' => ['Negative quantities require a privileged exception workflow.']]);
        }

        $variant = $this->variantInScope($session, $data['variant_id']);
        $unit = $this->assertUnitApproved($variant, $data['raw_unit_id']);

        $normalized = $this->normalize((float) $data['raw_quantity'], $unit);

        $entry = CountEntry::where('idempotency_key', $data['idempotency_key'])->first();
        if ($entry) {
            $entry->update([
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'stock_location_id' => $data['stock_location_id'] ?? null,
                'raw_quantity' => $data['raw_quantity'],
                'raw_unit_id' => $unit->id,
                'normalized_quantity' => $normalized,
                'observations' => $data['observations'] ?? null,
                'notes' => $data['notes'] ?? null,
                'worker_id' => $worker->id,
                'captured_at' => $data['captured_at'] ?? now(),
                'synced_at' => now(),
            ]);
        } else {
            $entry = CountEntry::create([
                'tenant_id' => $session->tenant_id,
                'session_id' => $session->id,
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'stock_location_id' => $data['stock_location_id'] ?? null,
                'raw_quantity' => $data['raw_quantity'],
                'raw_unit_id' => $unit->id,
                'normalized_quantity' => $normalized,
                'observations' => $data['observations'] ?? null,
                'notes' => $data['notes'] ?? null,
                'worker_id' => $worker->id,
                'idempotency_key' => $data['idempotency_key'],
                'status' => 'draft',
                'captured_at' => $data['captured_at'] ?? now(),
                'synced_at' => now(),
            ]);
        }

        return $this->entryPayload($entry->fresh());
    }

    /**
     * Revise an entry: preserve the current value as an append-only revision, then apply the new value.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function reviseEntry(JobAssignment $assignment, CountSession $session, CountEntry $entry, array $data, User $worker): array
    {
        $this->assertSessionOwned($assignment, $session, $worker, requireActive: true);
        abort_unless($entry->session_id === $session->id, 404);

        $data = validator($data, [
            'raw_quantity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'raw_unit_id' => ['required', 'ulid'],
            'observations' => ['nullable', 'array'],
            'observations.*' => ['string', 'in:good,damaged,expired,quarantined,missing_label,inaccessible,unknown'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ])->validate();

        if ((float) $data['raw_quantity'] < 0) {
            throw ValidationException::withMessages(['raw_quantity' => ['Negative quantities require a privileged exception workflow.']]);
        }

        $variant = $entry->variant_id
            ? ProductVariant::withoutGlobalScope('tenant')->whereKey($entry->variant_id)->with(['baseUnit', 'packagingUnit'])->first()
            : null;
        $unit = $variant
            ? $this->assertUnitApproved($variant, $data['raw_unit_id'])
            : UnitOfMeasure::withoutGlobalScope('tenant')->where('uuid', $data['raw_unit_id'])->firstOrFail();

        return DB::transaction(function () use ($entry, $data, $worker, $unit): array {
            CountEntryRevision::create([
                'entry_id' => $entry->id,
                'raw_quantity' => $entry->raw_quantity,
                'raw_unit_id' => $entry->raw_unit_id,
                'normalized_quantity' => $entry->normalized_quantity,
                'observations' => $entry->observations,
                'notes' => $entry->notes,
                'reason' => $data['reason'],
                'actor_id' => $worker->id,
            ]);

            $normalized = $this->normalize((float) $data['raw_quantity'], $unit);
            $entry->update([
                'raw_quantity' => $data['raw_quantity'],
                'raw_unit_id' => $unit->id,
                'normalized_quantity' => $normalized,
                'observations' => $data['observations'] ?? null,
                'notes' => $data['notes'] ?? null,
                'revision_count' => $entry->revision_count + 1,
            ]);

            return $this->entryPayload($entry->fresh()->loadMissing('revisions'));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function reportException(JobAssignment $assignment, CountSession $session, array $data, User $worker): array
    {
        $this->assertSessionOwned($assignment, $session, $worker, requireActive: true);
        $data = validator($data, [
            'variant_id' => ['nullable', 'ulid'],
            'barcode' => ['nullable', 'string', 'max:200'],
            'type' => ['required', 'string', 'in:mismatched_barcode,wrong_label,unexpected_variant,packaging,other'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'location' => ['nullable', 'string', 'max:200'],
            'observed_unit_id' => ['nullable', 'ulid'],
        ])->validate();

        $variant = isset($data['variant_id'])
            ? ProductVariant::withoutGlobalScope('tenant')
                ->where('tenant_id', $session->tenant_id)
                ->where('uuid', $data['variant_id'])
                ->first()
            : null;

        $observedUnitId = isset($data['observed_unit_id'])
            ? UnitOfMeasure::withoutGlobalScope('tenant')
                ->where('tenant_id', $session->tenant_id)
                ->where('uuid', $data['observed_unit_id'])
                ->value('id')
            : null;

        $exception = CountException::create([
            'tenant_id' => $session->tenant_id,
            'session_id' => $session->id,
            'product_id' => $variant?->product_id,
            'variant_id' => $variant?->id,
            'barcode' => $data['barcode'] ?? null,
            'type' => $data['type'],
            'description' => $data['description'],
            'location' => $data['location'] ?? null,
            'observed_unit_id' => $observedUnitId,
            'created_by' => $worker->id,
        ]);

        return ['id' => $exception->uuid, 'status' => $exception->status];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function reportUnknownProduct(JobAssignment $assignment, CountSession $session, array $data, User $worker): array
    {
        $this->assertSessionOwned($assignment, $session, $worker, requireActive: true);
        $data = validator($data, [
            'barcode' => ['nullable', 'string', 'max:200'],
            'name_guess' => ['nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'location' => ['nullable', 'string', 'max:200'],
            'observed_unit_id' => ['nullable', 'ulid'],
            'evidence' => ['nullable', 'array'],
        ])->validate();

        $observedUnitId = isset($data['observed_unit_id'])
            ? UnitOfMeasure::withoutGlobalScope('tenant')
                ->where('tenant_id', $session->tenant_id)
                ->where('uuid', $data['observed_unit_id'])
                ->value('id')
            : null;

        $report = UnknownProductReport::create([
            'tenant_id' => $session->tenant_id,
            'session_id' => $session->id,
            'barcode' => $data['barcode'] ?? null,
            'name_guess' => $data['name_guess'] ?? null,
            'description' => $data['description'],
            'location' => $data['location'] ?? null,
            'observed_unit_id' => $observedUnitId,
            'evidence' => $data['evidence'] ?? null,
            'created_by' => $worker->id,
        ]);

        return ['id' => $report->uuid, 'status' => $report->status];
    }

    /**
     * Submit a count session: lock entries, mark submitted, transition the job to count_submitted.
     *
     * @return array<string, mixed>
     */
    public function submit(JobAssignment $assignment, CountSession $session, User $worker): array
    {
        // Allow re-submitting an already-submitted session (idempotent); only require active on first submit.
        $this->assertSessionOwned($assignment, $session, $worker, requireActive: false);

        if (! in_array($session->status, ['active', 'submitted'], true)) {
            abort(422, 'This count session cannot be submitted (status: '.$session->status.').');
        }

        $job = $this->jobForAssignment($assignment);

        return DB::transaction(function () use ($session, $job, $worker): array {
            $locked = CountSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'submitted') {
                return $this->sessionPayload($locked->fresh(), $job);
            }

            if ($job->status !== 'in_progress') {
                throw ValidationException::withMessages(['count' => ['Counts can only be submitted while the job is in progress.']]);
            }

            CountSubmissionLock::create([
                'session_id' => $locked->id,
                'locked_by' => $worker->id,
            ]);

            CountEntry::where('session_id', $locked->id)->update(['status' => 'submitted']);

            $locked->update([
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            $this->workflow->transition($job, 'count_submitted', $worker, 'Worker submitted count session '.$locked->uuid);

            return $this->sessionPayload($locked->fresh()->loadMissing(['entries', 'lock']), $job);
        });
    }

    /**
     * Platform read of a job's count sessions. Never includes worker compensation (none in counts).
     *
     * @return array<string, mixed>
     */
    public function platformCounts(StocktakeJob $job): array
    {
        return $this->countsPayload($job);
    }

    /**
     * Tenant read of a job's count sessions. Tenant-scoped; no compensation, quote or margin.
     *
     * @return array<string, mixed>
     */
    public function tenantCounts(StocktakeJob $job): array
    {
        return $this->countsPayload($job);
    }

    // ----- public helpers used by OfflineSyncService -----

    public function scopeForPackage(StocktakeJob $job): array
    {
        return $this->scopePayload($job);
    }

    public function modeForJob(StocktakeJob $job): string
    {
        return $this->modeFor($job);
    }

    // ----- internals -----

    /** @return array<string, mixed> */
    private function countsPayload(StocktakeJob $job): array
    {
        $sessions = CountSession::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)
            ->with(['entries', 'exceptions', 'unknownProducts', 'lock', 'assignment.profile.user'])
            ->orderBy('id')
            ->get();

        return [
            'job' => $this->jobSummary($job),
            'sessions' => $sessions->map(fn (CountSession $s) => $this->sessionPayload($s, $job))->values()->all(),
            'summary' => [
                'sessions' => $sessions->count(),
                'submitted' => $sessions->where('status', 'submitted')->count(),
                'entries' => $sessions->flatMap->entries->count(),
                'exceptions' => $sessions->flatMap->exceptions->count(),
                'unknown_products' => $sessions->flatMap->unknownProducts->count(),
            ],
        ];
    }

    private function assertOwned(JobAssignment $assignment, User $worker): void
    {
        $profile = $this->profiles->forUser($worker);
        abort_if($profile === null, 404, 'Create your stock-taker profile first.');
        abort_unless($assignment->profile_id === $profile->id, 404);
    }

    private function ownedJob(JobAssignment $assignment, User $worker, bool $requireReady): StocktakeJob
    {
        $profile = $this->profiles->forUser($worker);
        abort_if($profile === null, 404, 'Create your stock-taker profile first.');
        abort_unless($assignment->profile_id === $profile->id, 404);

        $job = $this->jobForAssignment($assignment);

        if ($requireReady) {
            $this->operations->assertCanStartCount($assignment, $worker);
        }

        return $job;
    }

    private function jobForAssignment(JobAssignment $assignment): StocktakeJob
    {
        return StocktakeJob::withoutGlobalScope('tenant')
            ->whereKey($assignment->job_id)
            ->firstOrFail();
    }

    private function assertSessionOwned(JobAssignment $assignment, CountSession $session, User $worker, bool $requireActive): void
    {
        $profile = $this->profiles->forUser($worker);
        abort_if($profile === null, 404, 'Create your stock-taker profile first.');
        abort_unless($assignment->profile_id === $profile->id, 404);
        abort_unless($session->assignment_id === $assignment->id, 404);
        if ($requireActive) {
            abort_unless($session->status === 'active', 422, 'This count session is no longer active.');
        }
    }

    private function modeFor(StocktakeJob $job): string
    {
        $configured = $job->scope['count_mode'] ?? null;

        return $configured === 'guided' ? 'guided' : 'blind';
    }

    /** @return Collection<int, ProductVariant> */
    private function scopeVariants(StocktakeJob $job): Collection
    {
        $query = ProductVariant::withoutGlobalScope('tenant')
            ->where('tenant_id', $job->tenant_id)
            ->where('status', 'active')
            ->with(['product:id,uuid,name,brand,status', 'baseUnit:id,uuid,code,name,dimension,factor_to_base', 'packagingUnit:id,uuid,code,name,factor_to_base', 'barcodes:id,variant_id,barcode,type,is_primary']);

        $scope = $job->product_scope ?? 'all';
        $payload = $job->product_scope_payload ?? [];

        if ($scope === 'category' && ! empty($payload['category_ids'])) {
            $query->whereHas('product', fn ($q) => $q->whereIn('category_id', $payload['category_ids']));
        } elseif ($scope === 'variant' && ! empty($payload['variant_ids'])) {
            $query->whereIn('uuid', $payload['variant_ids']);
        } elseif ($scope === 'variant' && ! empty($payload['skus'])) {
            $query->whereIn('sku', $payload['skus']);
        }

        return $query->orderBy('sku')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function scopePayload(StocktakeJob $job): array
    {
        return $this->scopeVariants($job)->map(fn (ProductVariant $v) => $this->variantPayload($v))->values()->all();
    }

    /** @return array<string, mixed> */
    private function variantPayload(ProductVariant $v): array
    {
        $approvedUnits = collect([$v->baseUnit, $v->packagingUnit])
            ->filter()
            ->unique('id')
            ->map(fn (UnitOfMeasure $u) => ['id' => $u->uuid, 'code' => $u->code, 'name' => $u->name])
            ->values()->all();

        return [
            'id' => $v->uuid,
            'product' => ['id' => $v->product?->uuid, 'name' => $v->product?->name, 'brand' => $v->product?->brand],
            'sku' => $v->sku,
            'name' => $v->name,
            'barcodes' => $v->barcodes->map(fn ($b) => ['barcode' => $b->barcode, 'type' => $b->type, 'is_primary' => $b->is_primary])->values()->all(),
            'approved_units' => $approvedUnits,
            // Blind by default: no expected quantity, valuation, cost or margin is included.
        ];
    }

    /** @return array<string, mixed> */
    private function frozenScope(StocktakeJob $job): array
    {
        return [
            'count_mode' => $this->modeFor($job),
            'product_scope' => $job->product_scope,
            'product_scope_payload' => $job->product_scope_payload,
            'variant_ids' => $this->scopeVariants($job)->pluck('uuid')->all(),
        ];
    }

    private function variantInScope(CountSession $session, string $variantUuid): ProductVariant
    {
        $variant = ProductVariant::withoutGlobalScope('tenant')
            ->where('tenant_id', $session->tenant_id)
            ->where('uuid', $variantUuid)
            ->where('status', 'active')
            ->with(['baseUnit', 'packagingUnit', 'product'])
            ->first();

        if ($variant === null) {
            throw ValidationException::withMessages(['variant_id' => ['This product is not part of the assigned count scope.']]);
        }

        $frozen = $session->scope['variant_ids'] ?? null;
        if (is_array($frozen) && ! in_array($variant->uuid, $frozen, true)) {
            throw ValidationException::withMessages(['variant_id' => ['This product is not part of the assigned count scope.']]);
        }

        return $variant;
    }

    private function assertUnitApproved(ProductVariant $variant, string $unitUuid): UnitOfMeasure
    {
        $approved = collect([$variant->baseUnit, $variant->packagingUnit])
            ->filter()
            ->keyBy('uuid');

        $unit = $approved->get($unitUuid);

        if ($unit === null) {
            throw ValidationException::withMessages(['raw_unit_id' => ['Use an approved catalogue unit for this product.']]);
        }

        return $unit;
    }

    private function normalize(float $quantity, UnitOfMeasure $unit): float
    {
        return round($quantity * (float) $unit->factor_to_base, 6);
    }

    /** @return array<string, mixed> */
    private function sessionPayload(CountSession $session, StocktakeJob $job): array
    {
        $session->loadMissing(['entries', 'exceptions', 'unknownProducts', 'lock']);

        return [
            'id' => $session->uuid,
            'mode' => $session->mode,
            'status' => $session->status,
            'started_at' => $session->started_at?->toIso8601String(),
            'submitted_at' => $session->submitted_at?->toIso8601String(),
            'scope' => $this->scopePayload($job),
            'locked' => $session->lock !== null,
            'locked_at' => $session->lock?->locked_at?->toIso8601String(),
            'entries' => $session->entries->map(fn (CountEntry $e) => $this->entryPayload($e))->values()->all(),
            'exceptions' => $session->exceptions->map(fn (CountException $x) => [
                'id' => $x->uuid, 'type' => $x->type, 'description' => $x->description, 'status' => $x->status,
            ])->values()->all(),
            'unknown_products' => $session->unknownProducts->map(fn (UnknownProductReport $u) => [
                'id' => $u->uuid, 'barcode' => $u->barcode, 'name_guess' => $u->name_guess, 'status' => $u->status,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function entryPayload(CountEntry $entry): array
    {
        return [
            'id' => $entry->uuid,
            'variant_id' => $entry->variant?->uuid ?? $entry->variant_id,
            'product_id' => $entry->product?->uuid ?? $entry->product_id,
            'raw_quantity' => $entry->raw_quantity,
            'raw_unit_id' => $entry->rawUnit?->uuid,
            'normalized_quantity' => $entry->normalized_quantity,
            'observations' => $entry->observations ?? [],
            'notes' => $entry->notes,
            'status' => $entry->status,
            'revision_count' => $entry->revision_count,
            'captured_at' => $entry->captured_at?->toIso8601String(),
            'idempotency_key' => $entry->idempotency_key,
            'revisions' => $entry->relationLoaded('revisions')
                ? $entry->revisions->map(fn (CountEntryRevision $r) => [
                    'id' => $r->uuid, 'reason' => $r->reason, 'raw_quantity' => $r->raw_quantity, 'created_at' => $r->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function jobSummary(StocktakeJob $job): array
    {
        return [
            'id' => $job->uuid,
            'reference' => $job->reference,
            'title' => $job->title,
            'status' => $job->status,
            'scope_label' => $job->scope['label'] ?? null,
        ];
    }
}
