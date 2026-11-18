<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryBatch;
use App\Models\InventoryCutoff;
use App\Models\InventoryMovement;
use App\Models\InventorySerial;
use App\Models\InventorySnapshot;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class InventoryController extends Controller
{
    use ChecksTenantAccess;

    public function summary(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');
        $withCost = $this->canViewCost($request, $tenant);

        $totals = DB::table('inventory_balances as balances')
            ->join('product_variants as variants', 'variants.id', '=', 'balances.variant_id')
            ->join('products', 'products.id', '=', 'variants.product_id')
            ->where('balances.tenant_id', $tenant->id)
            ->selectRaw('COALESCE(SUM(balances.quantity), 0) as quantity')
            ->selectRaw($withCost ? 'COALESCE(SUM(balances.quantity * products.cost_price), 0) as value' : '0 as value')
            ->first();

        $latestSnapshot = InventorySnapshot::query()
            ->where('tenant_id', $tenant->id)
            ->with(['location', 'branch', 'catalogueVersion'])
            ->latest('snapshot_at')
            ->first();

        $openCutoff = InventoryCutoff::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->with(['location', 'branch'])
            ->latest('cut_off_at')
            ->first();

        $recentMovements = InventoryMovement::query()
            ->where('tenant_id', $tenant->id)
            ->with(['location', 'branch', 'cutoff', 'lines.variant.product', 'lines.variant.baseUnit'])
            ->latest('occurred_at')
            ->limit(5)
            ->get();

        $tracking = ProductVariant::query()
            ->where('product_variants.tenant_id', $tenant->id)
            ->where('product_variants.status', 'active')
            ->where('products.status', '!=', 'discontinued')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->groupBy('products.tracking_mode')
            ->selectRaw('products.tracking_mode, COUNT(*) as total')
            ->pluck('total', 'tracking_mode');

        return ApiResponse::success([
            'can_view_cost' => $withCost,
            'stats' => [
                'locations' => StockLocation::query()->where('tenant_id', $tenant->id)->count(),
                'tracked_variants' => (int) $tracking->sum(),
                'balance_rows' => InventoryBalance::query()->where('tenant_id', $tenant->id)->count(),
                'total_quantity' => round((float) ($totals->quantity ?? 0), 3),
                'stock_value' => $withCost ? round((float) ($totals->value ?? 0), 2) : null,
                'movements_30_days' => InventoryMovement::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('occurred_at', '>=', now()->subDays(30))
                    ->count(),
                'expiring_batches' => InventoryBatch::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->whereNotNull('expires_on')
                    ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count(),
                'expired_batches' => InventoryBatch::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereNotNull('expires_on')
                    ->where('expires_on', '<', now()->toDateString())
                    ->count(),
                'serials_in_stock' => InventorySerial::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'in_stock')
                    ->count(),
                'open_cutoffs' => InventoryCutoff::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'open')
                    ->count(),
            ],
            'tracking' => collect($tracking)->map(fn ($total, $mode) => [
                'mode' => $mode,
                'variants' => (int) $total,
            ])->values(),
            'latest_snapshot' => $latestSnapshot ? $this->snapshotPayload($latestSnapshot) : null,
            'open_cutoff' => $openCutoff ? $this->cutoffPayload($openCutoff) : null,
            'recent_movements' => $recentMovements
                ->map(fn (InventoryMovement $movement) => $this->movementPayload($movement, $withCost))
                ->values(),
        ]);
    }

    public function balances(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');
        $withCost = $this->canViewCost($request, $tenant);

        $filters = $request->validate([
            'stock_location' => ['nullable', 'string'],
            'branch' => ['nullable', 'string'],
            'warehouse' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'search_by' => ['nullable', Rule::in(['name', 'sku', 'barcode', 'brand', 'category', 'variant', 'description'])],
            'letter' => ['nullable', 'string', 'regex:/^(?:[a-z]|#)$/i'],
            'category' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'stock_status' => ['nullable', Rule::in(['in_stock', 'out_of_stock'])],
            'in_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = InventoryBalance::query()
            ->where('inventory_balances.tenant_id', $tenant->id)
            ->with(['variant.product', 'variant.baseUnit', 'location', 'batch'])
            ->when($filters['stock_location'] ?? null, fn ($query, $location) => $query->whereHas(
                'location',
                fn ($inner) => $inner->where('uuid', $location),
            ))
            ->when($filters['branch'] ?? null, fn ($query, $branch) => $query->whereHas(
                'location.branch',
                fn ($inner) => $inner->where('uuid', $branch)->where('tenant_id', $tenant->id),
            ))
            ->when($filters['warehouse'] ?? null, fn ($query, $warehouse) => $query->whereHas(
                'location.warehouse',
                fn ($inner) => $inner->where('uuid', $warehouse)->where('tenant_id', $tenant->id),
            ))
            ->when($filters['category'] ?? null, fn ($query, $category) => $query->whereHas(
                'variant.product.category',
                fn ($inner) => $inner->where('uuid', $category)->orWhere('name', $category),
            ))
            ->when($filters['brand'] ?? null, fn ($query, $brand) => $query->whereHas(
                'variant.product',
                fn ($inner) => $inner->where('brand', 'like', '%'.trim($brand).'%'),
            ))
            ->when($filters['letter'] ?? null, function ($query, string $letter): void {
                $query->whereHas('variant.product', function ($inner) use ($letter): void {
                    if ($letter === '#') {
                        $inner->whereRaw("UPPER(SUBSTR(name, 1, 1)) NOT BETWEEN 'A' AND 'Z'");

                        return;
                    }

                    $inner->whereRaw('LOWER(name) LIKE ?', [strtolower($letter).'%']);
                });
            })
            ->when($filters['search'] ?? null, function ($query, string $search) use ($filters): void {
                $search = trim($search);
                $field = $filters['search_by'] ?? 'name';
                $query->whereHas('variant', function ($variant) use ($field, $search): void {
                    if ($field === 'sku') {
                        $variant->where('sku', 'like', "%{$search}%");
                    } elseif ($field === 'barcode') {
                        $variant->whereHas('barcodes', fn ($barcode) => $barcode->where('barcode', 'like', "%{$search}%"));
                    } elseif ($field === 'variant') {
                        $variant->where('name', 'like', "%{$search}%")
                            ->orWhere('attributes', 'like', "%{$search}%");
                    } else {
                        $variant->whereHas('product', function ($product) use ($field, $search): void {
                            $column = match ($field) {
                                'brand' => 'brand',
                                'category' => 'category_id',
                                'description' => 'description',
                                default => 'name',
                            };
                            if ($field === 'category') {
                                $product->whereHas('category', fn ($category) => $category->where('name', 'like', "%{$search}%"));
                            } else {
                                $product->where($column, 'like', "%{$search}%");
                            }
                        });
                    }
                });
            })
            ->when(($filters['stock_status'] ?? null) === 'in_stock', fn ($query) => $query->where('quantity', '>', 0))
            ->when(($filters['stock_status'] ?? null) === 'out_of_stock', fn ($query) => $query->where('quantity', '<=', 0))
            ->when((bool) ($filters['in_stock'] ?? false), fn ($query) => $query->where('quantity', '>', 0))
            ->orderBy('inventory_balances.id');

        $page = $query->paginate(50);

        return ApiResponse::success(
            collect($page->items())->map(fn (InventoryBalance $balance) => $this->balancePayload($balance, $withCost))->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    public function batches(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'expiry' => ['nullable', 'in:all,soon,expired'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = InventoryBatch::query()
            ->where('inventory_batches.tenant_id', $tenant->id)
            ->with(['variant.product', 'variant.baseUnit'])
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('batch_number', 'like', "%{$search}%")
                        ->orWhereHas('variant', fn ($variant) => $variant->where('sku', 'like', "%{$search}%")
                            ->orWhereHas('product', fn ($product) => $product->where('name', 'like', "%{$search}%")));
                });
            })
            ->when(($filters['expiry'] ?? null) === 'soon', fn ($query) => $query->whereNotNull('expires_on')
                ->whereBetween('expires_on', [now()->toDateString(), now()->addDays(30)->toDateString()]))
            ->when(($filters['expiry'] ?? null) === 'expired', fn ($query) => $query->whereNotNull('expires_on')
                ->where('expires_on', '<', now()->toDateString()))
            ->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on');

        $page = $query->paginate(50);

        $balances = InventoryBalance::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('batch_id', collect($page->items())->pluck('id'))
            ->groupBy('batch_id')
            ->selectRaw('batch_id, SUM(quantity) as quantity')
            ->pluck('quantity', 'batch_id');

        return ApiResponse::success(
            collect($page->items())->map(function (InventoryBatch $batch) use ($balances): array {
                return [
                    'id' => $batch->uuid,
                    'batch_number' => $batch->batch_number,
                    'status' => $batch->status,
                    'manufactured_on' => $batch->manufactured_on?->toDateString(),
                    'expires_on' => $batch->expires_on?->toDateString(),
                    'days_to_expiry' => $batch->expires_on !== null ? (int) today()->diffInDays($batch->expires_on, false) : null,
                    'quantity' => round((float) ($balances[$batch->id] ?? 0), 3),
                    'variant_id' => $batch->variant?->uuid,
                    'sku' => $batch->variant?->sku,
                    'product_name' => $batch->variant?->product?->name,
                    'variant_name' => $batch->variant?->name,
                    'unit' => $batch->variant?->baseUnit?->code,
                ];
            })->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    public function serials(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:in_stock,sold,returned,scrapped,missing'],
            'stock_location' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = InventorySerial::query()
            ->where('inventory_serials.tenant_id', $tenant->id)
            ->with(['variant.product', 'batch', 'location'])
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('serial_number', 'like', "%{$search}%"))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['stock_location'] ?? null, fn ($query, $location) => $query->whereHas(
                'location',
                fn ($inner) => $inner->where('uuid', $location),
            ))
            ->orderByDesc('id');

        $page = $query->paginate(50);

        return ApiResponse::success(
            collect($page->items())->map(fn (InventorySerial $serial) => [
                'id' => $serial->uuid,
                'serial_number' => $serial->serial_number,
                'status' => $serial->status,
                'sku' => $serial->variant?->sku,
                'product_name' => $serial->variant?->product?->name,
                'batch_number' => $serial->batch?->batch_number,
                'stock_location' => $serial->location ? [
                    'id' => $serial->location->uuid,
                    'name' => $serial->location->name,
                ] : null,
                'updated_at' => $serial->updated_at?->toIso8601String(),
            ])->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    private function canViewCost(Request $request, Tenant $tenant): bool
    {
        return $request->user()->hasPermission('catalogue.update', $tenant->id);
    }

    public function balancePayload(InventoryBalance $balance, bool $withCost): array
    {
        $variant = $balance->variant;
        $cost = $variant?->product?->cost_price;

        return [
            'id' => $balance->uuid,
            'stock_location' => $balance->location ? [
                'id' => $balance->location->uuid,
                'name' => $balance->location->name,
            ] : null,
            'variant_id' => $variant?->uuid,
            'sku' => $variant?->sku,
            'product_name' => $variant?->product?->name,
            'variant_name' => $variant?->name,
            'unit' => $variant?->baseUnit?->code,
            'tracking_mode' => $variant?->product?->tracking_mode,
            'batch_number' => $balance->batch?->batch_number ?? ($balance->batch_key !== '' ? $balance->batch_key : null),
            'expires_on' => $balance->batch?->expires_on?->toDateString(),
            'quantity' => round((float) $balance->quantity, 3),
            'reserved_quantity' => round((float) $balance->reserved_quantity, 3),
            'available_quantity' => round((float) $balance->quantity - (float) $balance->reserved_quantity, 3),
            'value' => $withCost && $cost !== null ? round((float) $balance->quantity * (float) $cost, 2) : null,
            'last_movement_at' => $balance->last_movement_at?->toIso8601String(),
        ];
    }

    public function movementPayload(InventoryMovement $movement, bool $withCost): array
    {
        return [
            'id' => $movement->uuid,
            'type' => $movement->type,
            'source' => $movement->source,
            'external_id' => $movement->external_id,
            'reference' => $movement->reference,
            'status' => $movement->status,
            'occurred_at' => $movement->occurred_at?->toIso8601String(),
            'notes' => $movement->notes,
            'line_count' => $movement->line_count,
            'stock_location' => $movement->location ? [
                'id' => $movement->location->uuid,
                'name' => $movement->location->name,
            ] : null,
            'branch' => $movement->branch ? [
                'id' => $movement->branch->uuid,
                'name' => $movement->branch->name,
            ] : null,
            'cutoff_id' => $movement->cutoff?->uuid,
            'lines' => $movement->lines->map(fn ($line) => [
                'variant_id' => $line->variant?->uuid,
                'sku' => $line->variant?->sku,
                'product_name' => $line->variant?->product?->name,
                'variant_name' => $line->variant?->name,
                'unit' => $line->variant?->baseUnit?->code,
                'quantity' => round((float) $line->quantity, 3),
                'batch_number' => $line->batch_number,
                'serial_number' => $line->serial_number,
                'unit_cost' => $withCost && $line->unit_cost !== null ? (float) $line->unit_cost : null,
            ])->values(),
        ];
    }

    public function cutoffPayload(InventoryCutoff $cutoff): array
    {
        return [
            'id' => $cutoff->uuid,
            'status' => $cutoff->status,
            'cut_off_at' => $cutoff->cut_off_at?->toIso8601String(),
            'closed_at' => $cutoff->closed_at?->toIso8601String(),
            'notes' => $cutoff->notes,
            'movement_count' => $cutoff->movements_count ?? $cutoff->movements()->count(),
            'stock_location' => $cutoff->location ? [
                'id' => $cutoff->location->uuid,
                'name' => $cutoff->location->name,
            ] : null,
            'branch' => $cutoff->branch ? [
                'id' => $cutoff->branch->uuid,
                'name' => $cutoff->branch->name,
            ] : null,
            'created_at' => $cutoff->created_at?->toIso8601String(),
        ];
    }

    public function snapshotPayload(InventorySnapshot $snapshot, bool $withItems = false, bool $withCost = false): array
    {
        $payload = [
            'id' => $snapshot->uuid,
            'reference' => $snapshot->reference,
            'status' => $snapshot->status,
            'snapshot_at' => $snapshot->snapshot_at?->toIso8601String(),
            'item_count' => $snapshot->item_count,
            'total_quantity' => round((float) $snapshot->total_quantity, 3),
            'checksum' => $snapshot->checksum,
            'job_id' => $snapshot->job_id,
            'notes' => $snapshot->notes,
            'stock_location' => $snapshot->location ? [
                'id' => $snapshot->location->uuid,
                'name' => $snapshot->location->name,
            ] : null,
            'branch' => $snapshot->branch ? [
                'id' => $snapshot->branch->uuid,
                'name' => $snapshot->branch->name,
            ] : null,
            'catalogue_version' => $snapshot->catalogueVersion?->version_number,
            'cutoff_id' => $snapshot->cutoff?->uuid,
            'created_at' => $snapshot->created_at?->toIso8601String(),
        ];

        if ($withItems) {
            $payload['items'] = $snapshot->items->map(fn ($item) => [
                'id' => $item->id,
                'variant_id' => $item->variant?->uuid,
                'sku' => $item->sku,
                'product_name' => $item->product_name,
                'unit' => $item->unit,
                'batch_number' => $item->batch_number,
                'expires_on' => $item->expires_on?->toDateString(),
                'expected_quantity' => round((float) $item->expected_quantity, 3),
                'unit_cost' => $withCost && $item->unit_cost !== null ? (float) $item->unit_cost : null,
                'value' => $withCost && $item->value !== null ? (float) $item->value : null,
                'attributes' => $item->attributes,
            ])->values();
        }

        return $payload;
    }
}
