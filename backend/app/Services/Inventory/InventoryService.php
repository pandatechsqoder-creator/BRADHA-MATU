<?php

namespace App\Services\Inventory;

use App\Models\Branch;
use App\Models\Catalogue;
use App\Models\InventoryBalance;
use App\Models\InventoryBatch;
use App\Models\InventoryCutoff;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementLine;
use App\Models\InventorySerial;
use App\Models\InventorySnapshot;
use App\Models\InventorySnapshotItem;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryService
{
    public const MOVEMENT_TYPES = [
        'opening', 'purchase', 'sale', 'return', 'transfer_in', 'transfer_out',
        'adjustment', 'production', 'damage', 'expiry', 'consumption', 'other',
    ];

    private const OUTBOUND_TYPES = ['sale', 'transfer_out', 'damage', 'expiry', 'consumption'];

    private const BATCH_TRACKING = ['batch', 'expiry', 'combination'];

    private const SERIAL_TRACKING = ['serial', 'combination'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function resolveLocation(Tenant $tenant, ?string $uuid): ?StockLocation
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $location = StockLocation::query()
            ->where('tenant_id', $tenant->id)
            ->where('uuid', $uuid)
            ->first();

        if ($location === null) {
            throw ValidationException::withMessages([
                'stock_location_id' => ['Select a stock location that belongs to this business.'],
            ]);
        }

        return $location;
    }

    public function resolveBranch(Tenant $tenant, ?string $uuid): ?Branch
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $branch = Branch::query()->where('tenant_id', $tenant->id)->where('uuid', $uuid)->first();

        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch_id' => ['Select a branch that belongs to this business.'],
            ]);
        }

        return $branch;
    }

    public function resolveVariant(Tenant $tenant, ?string $uuid, ?string $sku): ProductVariant
    {
        $query = ProductVariant::query()->where('tenant_id', $tenant->id);

        if ($uuid !== null && $uuid !== '') {
            $query->where('uuid', $uuid);
        } elseif ($sku !== null && $sku !== '') {
            $query->where('sku', $sku);
        } else {
            throw ValidationException::withMessages([
                'lines' => ['Each movement line needs a product variant or SKU.'],
            ]);
        }

        $variant = $query->first();

        if ($variant === null) {
            throw ValidationException::withMessages([
                'lines' => ['One of the selected products does not belong to this business.'],
            ]);
        }

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordMovement(Tenant $tenant, array $data, ?User $actor, string $source = 'manual', bool $writeAudit = true): InventoryMovement
    {
        $location = $this->resolveLocation($tenant, $data['stock_location_id'] ?? null);

        if ($location === null) {
            throw ValidationException::withMessages([
                'stock_location_id' => ['Select the stock location this movement applies to.'],
            ]);
        }

        $type = strtolower((string) ($data['type'] ?? ''));
        if (! in_array($type, self::MOVEMENT_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Choose a valid movement type.']]);
        }

        $occurredAt = $this->parseTimestamp($data['occurred_at'] ?? null, 'occurred_at');

        $lines = $data['lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages(['lines' => ['Add at least one movement line.']]);
        }

        $movement = DB::transaction(function () use ($tenant, $data, $actor, $source, $location, $type, $occurredAt, $lines): InventoryMovement {
            $movement = InventoryMovement::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $location->branch_id,
                'stock_location_id' => $location->id,
                'type' => $type,
                'source' => $source,
                'external_id' => $data['external_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'occurred_at' => $occurredAt,
                'recorded_by' => $actor?->id,
                'status' => 'posted',
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $prefix = 'lines.';

            foreach (array_values($lines) as $index => $line) {
                $variant = $this->resolveVariant(
                    $tenant,
                    $line['variant_id'] ?? null,
                    $line['sku'] ?? null,
                );

                $quantity = (float) ($line['quantity'] ?? 0);
                if ($type === 'adjustment') {
                    if ($quantity === 0.0) {
                        throw ValidationException::withMessages([$prefix.$index.'.quantity' => ['Adjustment quantity cannot be zero.']]);
                    }
                    $signed = $quantity;
                } else {
                    if ($quantity <= 0) {
                        throw ValidationException::withMessages([$prefix.$index.'.quantity' => ['Quantity must be greater than zero.']]);
                    }
                    $signed = in_array($type, self::OUTBOUND_TYPES, true) ? -$quantity : $quantity;
                }

                $mode = $variant->product?->tracking_mode ?? 'quantity';
                $batchNumber = trim((string) ($line['batch_number'] ?? ''));

                if (in_array($mode, self::BATCH_TRACKING, true) && $batchNumber === '') {
                    throw ValidationException::withMessages([
                        $prefix.$index.'.batch_number' => ['This product is batch or expiry tracked; enter a batch number.'],
                    ]);
                }

                $batch = $batchNumber !== ''
                    ? $this->batchFor($tenant, $variant, $batchNumber, $line['expires_on'] ?? null, $line['manufactured_on'] ?? null)
                    : null;

                $serial = $this->syncSerial($tenant, $variant, $location, $batch, $line['serial_number'] ?? null, $signed, $type, $index, $prefix);

                InventoryMovementLine::create([
                    'tenant_id' => $tenant->id,
                    'movement_id' => $movement->id,
                    'variant_id' => $variant->id,
                    'batch_id' => $batch?->id,
                    'serial_id' => $serial?->id,
                    'batch_key' => $batchNumber,
                    'serial_number' => $serial?->serial_number,
                    'quantity' => $signed,
                    'unit_cost' => $line['unit_cost'] ?? null,
                    'metadata' => $line['metadata'] ?? null,
                ]);

                $this->applyBalance($tenant, $location, $variant, $batch, $batchNumber, $signed, $occurredAt);
            }

            $movement->update(['line_count' => count($lines)]);

            $cutoff = $this->openCutoffFor($tenant, $location, $occurredAt);
            if ($cutoff !== null) {
                $cutoff->movements()->syncWithoutDetaching([
                    $movement->id => ['tenant_id' => $tenant->id, 'included' => true, 'reason' => null],
                ]);
                $movement->update(['cutoff_id' => $cutoff->id]);
            }

            return $movement;
        });

        if ($writeAudit) {
            $this->audit->log('inventory.movement.created', $movement, null, null, [
                'type' => $movement->type,
                'source' => $movement->source,
                'reference' => $movement->reference,
                'location' => $location->name,
                'line_count' => $movement->line_count,
            ], $tenant->id);
        }

        return $movement->fresh(['lines.variant.product', 'location', 'branch']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function openCutoff(Tenant $tenant, array $data, ?User $actor): InventoryCutoff
    {
        $location = $this->resolveLocation($tenant, $data['stock_location_id'] ?? null);
        $branch = $location?->branch ?? $this->resolveBranch($tenant, $data['branch_id'] ?? null);

        if ($location === null && $branch === null) {
            throw ValidationException::withMessages([
                'branch_id' => ['Choose a branch or stock location for the cut-off.'],
            ]);
        }

        $cutOffAt = $this->parseTimestamp($data['cut_off_at'] ?? null, 'cut_off_at');

        $existing = InventoryCutoff::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->when($location !== null, fn ($query) => $query->where('stock_location_id', $location->id))
            ->when($location === null, fn ($query) => $query->whereNull('stock_location_id')->where('branch_id', $branch?->id))
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'stock_location_id' => ['A cut-off is already open for this scope. Close it before opening another.'],
            ]);
        }

        $cutoff = InventoryCutoff::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch?->id,
            'stock_location_id' => $location?->id,
            'cut_off_at' => $cutOffAt,
            'status' => 'open',
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor?->id,
        ]);

        $this->audit->log('inventory.cutoff.opened', $cutoff, null, null, [
            'cut_off_at' => $cutoff->cut_off_at?->toIso8601String(),
            'branch_id' => $branch?->uuid,
            'stock_location_id' => $location?->uuid,
        ], $tenant->id);

        return $cutoff;
    }

    public function closeCutoff(Tenant $tenant, InventoryCutoff $cutoff, ?User $actor): InventoryCutoff
    {
        if ($cutoff->status === 'closed') {
            throw ValidationException::withMessages(['cutoff' => ['This cut-off is already closed.']]);
        }

        DB::transaction(function () use ($tenant, $cutoff, $actor): void {
            $query = InventoryMovement::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('cutoff_id')
                ->where('occurred_at', '>=', $cutoff->cut_off_at);

            if ($cutoff->stock_location_id !== null) {
                $query->where('stock_location_id', $cutoff->stock_location_id);
            } elseif ($cutoff->branch_id !== null) {
                $query->where('branch_id', $cutoff->branch_id);
            }

            foreach ($query->get() as $movement) {
                $cutoff->movements()->syncWithoutDetaching([
                    $movement->id => ['tenant_id' => $tenant->id, 'included' => true, 'reason' => null],
                ]);
                $movement->update(['cutoff_id' => $cutoff->id]);
            }

            $cutoff->update([
                'status' => 'closed',
                'closed_by' => $actor?->id,
                'closed_at' => now(),
            ]);
        });

        $this->audit->log('inventory.cutoff.closed', $cutoff, null, null, [
            'movement_count' => $cutoff->movements()->count(),
        ], $tenant->id);

        return $cutoff->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createSnapshot(Tenant $tenant, array $data, ?User $actor): InventorySnapshot
    {
        $cutoff = null;
        if (! empty($data['cutoff_id'])) {
            $cutoff = InventoryCutoff::query()
                ->where('tenant_id', $tenant->id)
                ->where('uuid', $data['cutoff_id'])
                ->first();

            if ($cutoff === null) {
                throw ValidationException::withMessages(['cutoff_id' => ['Select a cut-off that belongs to this business.']]);
            }
        }

        $location = $this->resolveLocation($tenant, $data['stock_location_id'] ?? $cutoff?->location?->uuid);
        $branch = $location?->branch ?? $this->resolveBranch($tenant, $data['branch_id'] ?? $cutoff?->branch?->uuid);

        if ($location === null && $branch === null) {
            throw ValidationException::withMessages([
                'branch_id' => ['Choose a branch or stock location to snapshot.'],
            ]);
        }

        $snapshotAt = $cutoff?->cut_off_at !== null
            ? CarbonImmutable::instance($cutoff->cut_off_at)
            : $this->parseTimestamp($data['snapshot_at'] ?? null, 'snapshot_at');

        $catalogue = Catalogue::query()->where('tenant_id', $tenant->id)->orderByDesc('id')->first();
        $version = $catalogue?->currentVersion;

        if ($catalogue === null || $version === null) {
            throw ValidationException::withMessages([
                'catalogue' => ['Publish a catalogue version before taking a stock snapshot.'],
            ]);
        }

        $existing = InventorySnapshot::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'frozen')
            ->where('catalogue_version_id', $version->id)
            ->where('snapshot_at', $snapshotAt)
            ->when($location !== null, fn ($query) => $query->where('stock_location_id', $location->id))
            ->when($location === null, fn ($query) => $query->whereNull('stock_location_id')->where('branch_id', $branch?->id))
            ->first();

        if ($existing !== null) {
            return $existing->load('items');
        }

        $items = $this->expectedItems($tenant, $location, $branch, $snapshotAt);

        $checksum = hash('sha256', implode("\n", array_map(
            fn (array $item) => $item['sku'].'|'.$item['batch_number'].'|'.number_format((float) $item['expected_quantity'], 3, '.', ''),
            $items,
        )));

        $snapshot = DB::transaction(function () use ($tenant, $data, $actor, $location, $branch, $version, $cutoff, $snapshotAt, $items, $checksum): InventorySnapshot {
            InventorySnapshot::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'frozen')
                ->when($location !== null, fn ($query) => $query->where('stock_location_id', $location->id))
                ->when($location === null, fn ($query) => $query->whereNull('stock_location_id')->where('branch_id', $branch?->id))
                ->update(['status' => 'superseded']);

            $snapshot = InventorySnapshot::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch?->id,
                'stock_location_id' => $location?->id,
                'catalogue_version_id' => $version->id,
                'cutoff_id' => $cutoff?->id,
                'job_id' => $data['job_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'status' => 'frozen',
                'snapshot_at' => $snapshotAt,
                'item_count' => count($items),
                'total_quantity' => array_sum(array_column($items, 'expected_quantity')),
                'checksum' => $checksum,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            foreach ($items as $item) {
                InventorySnapshotItem::create([
                    'tenant_id' => $tenant->id,
                    'snapshot_id' => $snapshot->id,
                    ...$item,
                ]);
            }

            return $snapshot;
        });

        $this->audit->log('inventory.snapshot.created', $snapshot, null, null, [
            'item_count' => $snapshot->item_count,
            'total_quantity' => (float) $snapshot->total_quantity,
            'checksum' => $snapshot->checksum,
            'catalogue_version' => $version->version_number,
            'cutoff_id' => $cutoff?->uuid,
            'job_id' => $snapshot->job_id,
        ], $tenant->id);

        return $snapshot->fresh(['items', 'location', 'branch', 'catalogueVersion', 'cutoff']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expectedItems(Tenant $tenant, ?StockLocation $location, ?Branch $branch, CarbonImmutable $snapshotAt): array
    {
        $query = DB::table('inventory_movement_lines as lines')
            ->join('inventory_movements as movements', 'movements.id', '=', 'lines.movement_id')
            ->where('lines.tenant_id', $tenant->id)
            ->where('movements.tenant_id', $tenant->id)
            ->where('movements.status', 'posted')
            ->where('movements.occurred_at', '<=', $snapshotAt);

        if ($location !== null) {
            $query->where('movements.stock_location_id', $location->id);
        } elseif ($branch !== null) {
            $query->where('movements.branch_id', $branch->id);
        }

        $quantities = $query
            ->groupBy('lines.variant_id', 'lines.batch_key')
            ->selectRaw('lines.variant_id, lines.batch_key, SUM(lines.quantity) as quantity')
            ->get();

        $byVariant = [];
        foreach ($quantities as $row) {
            $byVariant[(int) $row->variant_id][(string) $row->batch_key] = (float) $row->quantity;
        }

        $variants = ProductVariant::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereHas('product', fn ($product) => $product->where('tenant_id', $tenant->id)->where('status', '!=', 'discontinued'))
            ->with(['product', 'baseUnit'])
            ->get();

        $batches = InventoryBatch::query()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy(fn (InventoryBatch $batch) => $batch->variant_id.'|'.$batch->batch_number);

        $items = [];

        foreach ($variants as $variant) {
            /** @var ProductVariant $variant */
            $mode = $variant->product?->tracking_mode ?? 'quantity';
            $tracked = in_array($mode, self::BATCH_TRACKING, true);
            $rows = $byVariant[$variant->id] ?? [];
            $cost = $variant->product?->cost_price !== null ? (float) $variant->product->cost_price : null;

            $build = function (?string $batchKey, float $quantity) use ($variant, $batches, $cost): array {
                $batch = $batchKey !== null && $batchKey !== ''
                    ? $batches->get($variant->id.'|'.$batchKey)
                    : null;

                return [
                    'variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'product_name' => $variant->product?->name ?? $variant->sku,
                    'unit' => $variant->baseUnit?->code,
                    'batch_id' => $batch?->id,
                    'batch_number' => $batch?->batch_number,
                    'expires_on' => $batch?->expires_on,
                    'expected_quantity' => round($quantity, 3),
                    'unit_cost' => $cost,
                    'value' => $cost !== null ? round($quantity * $cost, 2) : null,
                    'attributes' => $variant->attributes,
                ];
            };

            if (! $tracked) {
                $items[] = $build(null, (float) ($rows[''] ?? 0));
                continue;
            }

            if ($rows === []) {
                $items[] = $build(null, 0.0);
                continue;
            }

            foreach ($rows as $batchKey => $quantity) {
                $items[] = $build((string) $batchKey, (float) $quantity);
            }
        }

        usort($items, fn (array $a, array $b) => [$a['sku'], (string) $a['batch_number']] <=> [$b['sku'], (string) $b['batch_number']]);

        return $items;
    }

    private function applyBalance(
        Tenant $tenant,
        StockLocation $location,
        ProductVariant $variant,
        ?InventoryBatch $batch,
        string $batchKey,
        float $signedQuantity,
        CarbonImmutable $occurredAt,
    ): void {
        $balance = InventoryBalance::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'stock_location_id' => $location->id,
                'variant_id' => $variant->id,
                'batch_key' => $batchKey,
            ],
            [
                'branch_id' => $location->branch_id,
                'batch_id' => $batch?->id,
                'quantity' => 0,
                'reserved_quantity' => 0,
            ],
        );

        $balance->quantity = (float) $balance->quantity + $signedQuantity;
        $balance->last_movement_at = $occurredAt;
        if ($balance->batch_id === null && $batch !== null) {
            $balance->batch_id = $batch->id;
        }
        $balance->save();
    }

    private function batchFor(
        Tenant $tenant,
        ProductVariant $variant,
        string $batchNumber,
        ?string $expiresOn,
        ?string $manufacturedOn,
    ): InventoryBatch {
        $batch = InventoryBatch::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'variant_id' => $variant->id,
                'batch_number' => $batchNumber,
            ],
            [
                'expires_on' => $this->parseDate($expiresOn, 'expires_on'),
                'manufactured_on' => $this->parseDate($manufacturedOn, 'manufactured_on'),
                'status' => 'active',
            ],
        );

        if ($batch->expires_on === null && $expiresOn !== null && $expiresOn !== '') {
            $batch->update(['expires_on' => $this->parseDate($expiresOn, 'expires_on')]);
        }

        return $batch;
    }

    private function syncSerial(
        Tenant $tenant,
        ProductVariant $variant,
        StockLocation $location,
        ?InventoryBatch $batch,
        ?string $serialNumber,
        float $signedQuantity,
        string $type,
        int $index,
        string $prefix,
    ): ?InventorySerial {
        $serialNumber = trim((string) $serialNumber);
        $mode = $variant->product?->tracking_mode ?? 'quantity';

        if ($serialNumber === '') {
            if (in_array($mode, self::SERIAL_TRACKING, true)) {
                throw ValidationException::withMessages([
                    $prefix.$index.'.serial_number' => ['This product is serial tracked; enter a serial number.'],
                ]);
            }

            return null;
        }

        $serial = InventorySerial::query()
            ->where('tenant_id', $tenant->id)
            ->where('serial_number', $serialNumber)
            ->first();

        if ($serial !== null && $serial->variant_id !== $variant->id) {
            throw ValidationException::withMessages([
                $prefix.$index.'.serial_number' => ['This serial number belongs to a different product.'],
            ]);
        }

        if ($signedQuantity < 0) {
            if ($serial === null) {
                throw ValidationException::withMessages([
                    $prefix.$index.'.serial_number' => ['This serial number is not registered in stock.'],
                ]);
            }

            $status = match ($type) {
                'sale' => 'sold',
                'damage', 'expiry' => 'scrapped',
                default => 'in_stock',
            };

            $serial->update([
                'status' => $status,
                'stock_location_id' => $status === 'in_stock' ? $location->id : null,
            ]);

            return $serial;
        }

        return InventorySerial::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'serial_number' => $serialNumber],
            [
                'variant_id' => $variant->id,
                'batch_id' => $batch?->id,
                'status' => 'in_stock',
                'stock_location_id' => $location->id,
            ],
        );
    }

    private function openCutoffFor(Tenant $tenant, StockLocation $location, CarbonImmutable $occurredAt): ?InventoryCutoff
    {
        return InventoryCutoff::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->where('cut_off_at', '<=', $occurredAt)
            ->where(function ($query) use ($location): void {
                $query->whereNull('stock_location_id')->orWhere('stock_location_id', $location->id);
            })
            ->where(function ($query) use ($location): void {
                $query->whereNull('branch_id');
                if ($location->branch_id !== null) {
                    $query->orWhere('branch_id', $location->branch_id);
                }
            })
            ->orderByDesc('cut_off_at')
            ->first();
    }

    private function parseTimestamp(mixed $value, string $field): CarbonImmutable
    {
        if ($value === null || $value === '') {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['Enter a valid date and time.']]);
        }
    }

    private function parseDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['Enter a valid date.']]);
        }
    }
}
