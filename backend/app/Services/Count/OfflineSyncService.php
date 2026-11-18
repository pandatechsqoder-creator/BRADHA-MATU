<?php

namespace App\Services\Count;

use App\Models\CountDeviceSession;
use App\Models\CountEntry;
use App\Models\CountException;
use App\Models\CountSession;
use App\Models\CountSyncBatch;
use App\Models\CountSyncConflict;
use App\Models\JobAssignment;
use App\Models\ProductVariant;
use App\Models\StocktakeJob;
use App\Models\UnknownProductReport;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handles batch synchronisation of offline-queued count operations.
 *
 * Each item in the batch carries its own idempotency_key so the server can:
 *   - Return "synced"   if the key already exists with an identical or compatible value.
 *   - Return "conflict" if the key exists with a different value that cannot be auto-merged.
 *   - Return "synced"   with created=true if the key is new.
 *   - Return "rejected" if validation fails (scope, unit, etc.).
 */
final class OfflineSyncService
{
    public function __construct(private readonly CountService $counts) {}

    /**
     * Download the full offline job package for local caching.
     * Never includes expected stock, valuation, compensation or margin.
     *
     * @return array<string, mixed>
     */
    public function jobPackage(JobAssignment $assignment, User $worker, ?string $deviceFingerprint, ?string $platform, ?string $appVersion): array
    {
        $profile = resolve(\App\Services\Workforce\WorkforceProfileService::class)->forUser($worker);
        abort_if($profile === null, 404, 'Create your stock-taker profile first.');
        abort_unless($assignment->profile_id === $profile->id, 404);

        $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();

        // Record / update the device session
        $this->upsertDeviceSession(
            $assignment, $worker, $deviceFingerprint, $platform, $appVersion, packageDownload: true,
        );

        // Fetch the active count session if one exists
        $session = CountSession::where('assignment_id', $assignment->id)
            ->whereIn('status', ['active'])
            ->latest('id')
            ->first();

        // Build a compact scope payload (product identity + approved units only)
        $scope = $this->counts->scopeForPackage($job);

        return [
            'job' => [
                'id' => $job->uuid,
                'reference' => $job->reference,
                'title' => $job->title,
                'status' => $job->status,
                'scope_label' => $job->scope['label'] ?? null,
                'preferred_start_at' => $job->preferred_start_at?->toIso8601String(),
                'deadline_at' => $job->deadline_at?->toIso8601String(),
            ],
            'assignment' => [
                'id' => $assignment->uuid,
                'role' => $assignment->role,
                'status' => $assignment->status,
            ],
            'mode' => $this->counts->modeForJob($job),
            'scope' => $scope,
            'session_id' => $session?->uuid,
            'expires_at' => now()->addHours(24)->toIso8601String(),
            'downloaded_at' => now()->toIso8601String(),
            'offline_note' => 'This package is for offline use only. All entries must sync before they are saved. Changes are not committed until the network sync completes.',
        ];
    }

    /**
     * Process a batch of offline-queued operations.
     * Returns per-item sync status: synced | conflict | rejected.
     *
     * Input shape:
     *   {
     *     device_session_id?: string,
     *     items: [
     *       {
     *         type: 'entry' | 'exception' | 'unknown_product',
     *         idempotency_key: string,
     *         ... type-specific fields
     *       }
     *     ]
     *   }
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function syncBatch(JobAssignment $assignment, CountSession $session, array $data, User $worker): array
    {
        $profile = resolve(\App\Services\Workforce\WorkforceProfileService::class)->forUser($worker);
        abort_if($profile === null, 404, 'Create your stock-taker profile first.');
        abort_unless($assignment->profile_id === $profile->id, 404);
        abort_unless($session->assignment_id === $assignment->id, 404);

        if ($session->status === 'submitted') {
            // A submitted session accepts idempotent re-syncs but no new writes.
            $items = $data['items'] ?? [];
            $results = $this->processSubmittedSync($session, $items);

            return $this->batchPayload($session, $results, $data['device_session_id'] ?? null, $worker);
        }

        if ($session->status !== 'active') {
            abort(422, 'Count session is not accepting sync (status: '.$session->status.').');
        }

        $items = $data['items'] ?? [];
        $results = DB::transaction(function () use ($session, $items, $worker): array {
            return array_map(fn ($item) => $this->processItem($session, $item, $worker), $items);
        });

        return $this->batchPayload($session, $results, $data['device_session_id'] ?? null, $worker);
    }

    /**
     * Record a heartbeat / device-seen event without a package download.
     */
    public function heartbeat(JobAssignment $assignment, User $worker, ?string $deviceFingerprint, ?string $platform, bool $offlineCapable): void
    {
        $profile = resolve(\App\Services\Workforce\WorkforceProfileService::class)->forUser($worker);
        abort_if($profile === null, 404);
        abort_unless($assignment->profile_id === $profile->id, 404);

        $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();
        $this->upsertDeviceSession($assignment, $worker, $deviceFingerprint, $platform, null, packageDownload: false, offlineCapable: $offlineCapable);
    }

    // ----- internals -----

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function processItem(CountSession $session, array $item, User $worker): array
    {
        $key = (string) ($item['idempotency_key'] ?? '');
        $type = (string) ($item['type'] ?? '');

        if ($key === '') {
            return ['idempotency_key' => $key, 'type' => $type, 'status' => 'rejected', 'reason' => 'idempotency_key is required.'];
        }

        try {
            return match ($type) {
                'entry' => $this->syncEntry($session, $item, $worker),
                'exception' => $this->syncException($session, $item, $worker),
                'unknown_product' => $this->syncUnknownProduct($session, $item, $worker),
                default => ['idempotency_key' => $key, 'type' => $type, 'status' => 'rejected', 'reason' => "Unknown item type: {$type}."],
            };
        } catch (ValidationException $e) {
            return [
                'idempotency_key' => $key,
                'type' => $type,
                'status' => 'rejected',
                'reason' => collect($e->errors())->flatten()->first() ?? 'Validation failed.',
            ];
        } catch (\Throwable $e) {
            return [
                'idempotency_key' => $key,
                'type' => $type,
                'status' => 'rejected',
                'reason' => 'Server error processing this item.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function syncEntry(CountSession $session, array $item, User $worker): array
    {
        $key = (string) $item['idempotency_key'];
        $existing = CountEntry::where('idempotency_key', $key)->first();

        if ($existing !== null) {
            // Idempotent: same values → synced. Different values → conflict.
            if ($this->entryValuesMatch($existing, $item)) {
                return ['idempotency_key' => $key, 'type' => 'entry', 'status' => 'synced', 'id' => $existing->uuid, 'created' => false];
            }

            // If the session is active and the entry is a draft, the client wins (last-write).
            if ($existing->status === 'draft') {
                $this->applyEntryUpdate($session, $existing, $item, $worker);

                return ['idempotency_key' => $key, 'type' => 'entry', 'status' => 'synced', 'id' => $existing->uuid, 'created' => false];
            }

            // Entry is submitted — record a conflict, do not overwrite.
            $this->recordConflict($session, $existing, $item);

            return [
                'idempotency_key' => $key,
                'type' => 'entry',
                'status' => 'conflict',
                'id' => $existing->uuid,
                'reason' => 'Entry is already submitted and cannot be overwritten.',
            ];
        }

        // New entry — validate and create.
        $variantUuid = (string) ($item['variant_id'] ?? '');
        $unitUuid = (string) ($item['raw_unit_id'] ?? '');

        $variant = ProductVariant::withoutGlobalScope('tenant')
            ->where('tenant_id', $session->tenant_id)
            ->where('uuid', $variantUuid)
            ->where('status', 'active')
            ->with(['baseUnit', 'packagingUnit'])
            ->first();

        if ($variant === null) {
            throw ValidationException::withMessages(['variant_id' => ['Product not found or not in scope.']]);
        }

        $frozen = $session->scope['variant_ids'] ?? null;
        if (is_array($frozen) && ! in_array($variant->uuid, $frozen, true)) {
            throw ValidationException::withMessages(['variant_id' => ['This product is not part of the assigned count scope.']]);
        }

        $unit = $this->approvedUnit($variant, $unitUuid);
        $qty = (float) ($item['raw_quantity'] ?? 0);
        if ($qty < 0) {
            throw ValidationException::withMessages(['raw_quantity' => ['Negative quantities are not allowed.']]);
        }

        $normalized = round($qty * (float) $unit->factor_to_base, 6);

        $entry = CountEntry::create([
            'tenant_id' => $session->tenant_id,
            'session_id' => $session->id,
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'stock_location_id' => null,
            'raw_quantity' => $qty,
            'raw_unit_id' => $unit->id,
            'normalized_quantity' => $normalized,
            'observations' => $item['observations'] ?? null,
            'notes' => $item['notes'] ?? null,
            'worker_id' => $worker->id,
            'device_session_id' => $item['device_session_id'] ?? null,
            'idempotency_key' => $key,
            'status' => 'draft',
            'captured_at' => isset($item['captured_at']) ? date('Y-m-d H:i:s', strtotime($item['captured_at'])) : now(),
            'synced_at' => now(),
        ]);

        return ['idempotency_key' => $key, 'type' => 'entry', 'status' => 'synced', 'id' => $entry->uuid, 'created' => true];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function syncException(CountSession $session, array $item, User $worker): array
    {
        $key = (string) $item['idempotency_key'];

        // Exceptions don't have a unique idempotency key column — use type+barcode+description as natural key
        // For true idempotency, check by idempotency_key stored in the description prefix
        $existing = CountException::where('session_id', $session->id)
            ->where('type', $item['type_detail'] ?? $item['type'] ?? '')
            ->where('description', $item['description'] ?? '')
            ->first();

        if ($existing !== null) {
            return ['idempotency_key' => $key, 'type' => 'exception', 'status' => 'synced', 'id' => $existing->uuid, 'created' => false];
        }

        $exType = (string) ($item['type_detail'] ?? $item['exception_type'] ?? 'other');
        $allowed = ['mismatched_barcode', 'wrong_label', 'unexpected_variant', 'packaging', 'other'];
        if (! in_array($exType, $allowed, true)) {
            $exType = 'other';
        }

        $exception = CountException::create([
            'tenant_id' => $session->tenant_id,
            'session_id' => $session->id,
            'type' => $exType,
            'barcode' => $item['barcode'] ?? null,
            'description' => $item['description'] ?? '',
            'location' => $item['location'] ?? null,
            'created_by' => $worker->id,
        ]);

        return ['idempotency_key' => $key, 'type' => 'exception', 'status' => 'synced', 'id' => $exception->uuid, 'created' => true];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function syncUnknownProduct(CountSession $session, array $item, User $worker): array
    {
        $key = (string) $item['idempotency_key'];

        $existing = UnknownProductReport::where('session_id', $session->id)
            ->where('barcode', $item['barcode'] ?? null)
            ->where('description', $item['description'] ?? '')
            ->first();

        if ($existing !== null) {
            return ['idempotency_key' => $key, 'type' => 'unknown_product', 'status' => 'synced', 'id' => $existing->uuid, 'created' => false];
        }

        $report = UnknownProductReport::create([
            'tenant_id' => $session->tenant_id,
            'session_id' => $session->id,
            'barcode' => $item['barcode'] ?? null,
            'name_guess' => $item['name_guess'] ?? null,
            'description' => $item['description'] ?? '',
            'location' => $item['location'] ?? null,
            'created_by' => $worker->id,
        ]);

        return ['idempotency_key' => $key, 'type' => 'unknown_product', 'status' => 'synced', 'id' => $report->uuid, 'created' => true];
    }

    /**
     * For already-submitted sessions: only accept idempotent re-syncs, never new writes.
     *
     * @param  array<string, mixed>[]  $items
     * @return array<string, mixed>[]
     */
    private function processSubmittedSync(CountSession $session, array $items): array
    {
        return array_map(function (array $item) use ($session): array {
            $key = (string) ($item['idempotency_key'] ?? '');
            $type = (string) ($item['type'] ?? '');

            if ($type === 'entry') {
                $existing = CountEntry::where('idempotency_key', $key)->first();
                if ($existing) {
                    if (! $this->entryValuesMatch($existing, $item)) {
                        $this->recordConflict($session, $existing, $item);
                    }

                    return ['idempotency_key' => $key, 'type' => $type, 'status' => 'synced', 'id' => $existing->uuid, 'created' => false];
                }

                return ['idempotency_key' => $key, 'type' => $type, 'status' => 'rejected', 'reason' => 'Session is submitted; no new entries accepted.'];
            }

            return ['idempotency_key' => $key, 'type' => $type, 'status' => 'synced'];
        }, $items);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function entryValuesMatch(CountEntry $entry, array $item): bool
    {
        return (float) $entry->raw_quantity === (float) ($item['raw_quantity'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function applyEntryUpdate(CountSession $session, CountEntry $entry, array $item, User $worker): void
    {
        $variantUuid = (string) ($item['variant_id'] ?? '');
        $unitUuid = (string) ($item['raw_unit_id'] ?? '');

        $variant = ProductVariant::withoutGlobalScope('tenant')
            ->where('uuid', $variantUuid)->with(['baseUnit', 'packagingUnit'])->first();

        $unit = $variant ? $this->approvedUnit($variant, $unitUuid) : null;
        $qty = (float) ($item['raw_quantity'] ?? 0);
        $normalized = $unit ? round($qty * (float) $unit->factor_to_base, 6) : $qty;

        $entry->update([
            'raw_quantity' => $qty,
            'raw_unit_id' => $unit?->id ?? $entry->raw_unit_id,
            'normalized_quantity' => $normalized,
            'observations' => $item['observations'] ?? null,
            'notes' => $item['notes'] ?? null,
            'synced_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $clientItem
     */
    private function recordConflict(CountSession $session, CountEntry $existing, array $clientItem): void
    {
        CountSyncConflict::create([
            'session_id' => $session->id,
            'entry_id' => $existing->id,
            'idempotency_key' => $existing->idempotency_key,
            'client_value' => $clientItem,
            'server_value' => [
                'raw_quantity' => $existing->raw_quantity,
                'raw_unit_id' => $existing->rawUnit?->uuid,
                'observations' => $existing->observations,
                'notes' => $existing->notes,
                'status' => $existing->status,
            ],
        ]);
    }

    private function approvedUnit(ProductVariant $variant, string $unitUuid): UnitOfMeasure
    {
        $unit = collect([$variant->baseUnit, $variant->packagingUnit])
            ->filter()
            ->keyBy('uuid')
            ->get($unitUuid);

        if ($unit === null) {
            throw ValidationException::withMessages(['raw_unit_id' => ['Use a catalogue-approved unit for this product.']]);
        }

        return $unit;
    }

    /**
     * @param  array<string, mixed>[]  $results
     * @return array<string, mixed>
     */
    private function batchPayload(CountSession $session, array $results, ?string $deviceSessionId, User $worker): array
    {
        $synced = count(array_filter($results, fn ($r) => $r['status'] === 'synced'));
        $conflicts = count(array_filter($results, fn ($r) => $r['status'] === 'conflict'));
        $rejected = count(array_filter($results, fn ($r) => $r['status'] === 'rejected'));

        CountSyncBatch::create([
            'session_id' => $session->id,
            'worker_id' => $worker->id,
            'device_session_id' => $deviceSessionId,
            'items_total' => count($results),
            'items_synced' => $synced,
            'items_conflict' => $conflicts,
            'items_rejected' => $rejected,
            'results' => $results,
            'synced_at' => now(),
        ]);

        return [
            'session_id' => $session->uuid,
            'summary' => [
                'total' => count($results),
                'synced' => $synced,
                'conflict' => $conflicts,
                'rejected' => $rejected,
            ],
            'items' => $results,
            'synced_at' => now()->toIso8601String(),
        ];
    }

    private function upsertDeviceSession(
        JobAssignment $assignment,
        User $worker,
        ?string $fingerprint,
        ?string $platform,
        ?string $appVersion,
        bool $packageDownload,
        bool $offlineCapable = true,
    ): void {
        $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();

        $ds = CountDeviceSession::where('assignment_id', $assignment->id)
            ->where('worker_id', $worker->id)
            ->when($fingerprint, fn ($q) => $q->where('device_fingerprint', $fingerprint))
            ->first();

        if ($ds) {
            $updates = ['last_seen_at' => now()];
            if ($packageDownload) {
                $updates['package_downloaded_at'] = now();
            }
            $ds->update($updates);
        } else {
            CountDeviceSession::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'assignment_id' => $assignment->id,
                'worker_id' => $worker->id,
                'device_fingerprint' => $fingerprint,
                'platform' => $platform,
                'app_version' => $appVersion,
                'offline_capable' => $offlineCapable,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'package_downloaded_at' => $packageDownload ? now() : null,
            ]);
        }
    }
}
