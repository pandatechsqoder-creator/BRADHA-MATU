<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\InventorySnapshot;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventorySnapshotController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');

        $filters = $request->validate([
            'status' => ['nullable', 'in:frozen,superseded'],
            'stock_location' => ['nullable', 'string'],
        ]);

        $snapshots = InventorySnapshot::query()
            ->where('tenant_id', $tenant->id)
            ->with(['location', 'branch', 'catalogueVersion', 'cutoff'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['stock_location'] ?? null, fn ($query, $location) => $query->whereHas(
                'location',
                fn ($inner) => $inner->where('uuid', $location),
            ))
            ->orderByDesc('snapshot_at')
            ->limit(60)
            ->get();

        return ApiResponse::success(
            $snapshots->map(fn (InventorySnapshot $snapshot) => $presenter->snapshotPayload($snapshot))->values(),
        );
    }

    public function store(Request $request, Tenant $tenant, InventoryService $inventory, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.snapshot.create');

        $data = $request->validate([
            'cutoff_id' => ['nullable', 'string'],
            'stock_location_id' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'string'],
            'snapshot_at' => ['nullable', 'date'],
            'job_id' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $snapshot = $inventory->createSnapshot($tenant, $data, $request->user());
        $withCost = $request->user()->hasPermission('catalogue.update', $tenant->id);

        return ApiResponse::success($presenter->snapshotPayload($snapshot, true, $withCost), 201);
    }

    public function show(Request $request, Tenant $tenant, InventorySnapshot $snapshot, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');
        $this->assertBelongsToTenant($tenant, $snapshot);

        $withCost = $request->user()->hasPermission('catalogue.update', $tenant->id);
        $snapshot->load(['items.variant', 'location', 'branch', 'catalogueVersion', 'cutoff']);

        return ApiResponse::success($presenter->snapshotPayload($snapshot, true, $withCost));
    }

    public function items(Request $request, Tenant $tenant, InventorySnapshot $snapshot, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');
        $this->assertBelongsToTenant($tenant, $snapshot);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $snapshot->items()
            ->with('variant')
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('sku', 'like', "%{$search}%")
                        ->orWhere('product_name', 'like', "%{$search}%")
                        ->orWhere('batch_number', 'like', "%{$search}%");
                });
            })
            ->orderBy('sku');

        $page = $query->paginate(50);
        $withCost = $request->user()->hasPermission('catalogue.update', $tenant->id);

        return ApiResponse::success(
            collect($page->items())->map(fn ($item) => [
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
}
