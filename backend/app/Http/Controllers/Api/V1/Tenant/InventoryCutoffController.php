<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\InventoryCutoff;
use App\Models\InventorySnapshot;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryCutoffController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');

        $status = $request->validate([
            'status' => ['nullable', 'in:open,closed'],
        ])['status'] ?? null;

        $cutoffs = InventoryCutoff::query()
            ->where('tenant_id', $tenant->id)
            ->with(['location', 'branch'])
            ->withCount('movements')
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('cut_off_at')
            ->limit(60)
            ->get();

        return ApiResponse::success(
            $cutoffs->map(fn (InventoryCutoff $cutoff) => $presenter->cutoffPayload($cutoff))->values(),
        );
    }

    public function store(Request $request, Tenant $tenant, InventoryService $inventory, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.snapshot.create');

        $data = $request->validate([
            'stock_location_id' => ['nullable', 'string'],
            'branch_id' => ['nullable', 'string'],
            'cut_off_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $cutoff = $inventory->openCutoff($tenant, $data, $request->user());

        return ApiResponse::success($presenter->cutoffPayload($cutoff->load(['location', 'branch'])), 201);
    }

    public function show(Request $request, Tenant $tenant, InventoryCutoff $cutoff, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.view');
        $this->assertBelongsToTenant($tenant, $cutoff);

        $withCost = $request->user()->hasPermission('catalogue.update', $tenant->id);
        $cutoff->load(['location', 'branch', 'movements.lines.variant.product', 'movements.lines.variant.baseUnit']);

        $snapshots = InventorySnapshot::query()
            ->where('tenant_id', $tenant->id)
            ->where('cutoff_id', $cutoff->id)
            ->with(['location', 'branch', 'catalogueVersion', 'cutoff'])
            ->orderByDesc('snapshot_at')
            ->get();

        return ApiResponse::success([
            ...$presenter->cutoffPayload($cutoff),
            'movements' => $cutoff->movements
                ->sortByDesc('occurred_at')
                ->values()
                ->map(fn ($movement) => $presenter->movementPayload($movement, $withCost)),
            'snapshots' => $snapshots
                ->map(fn (InventorySnapshot $snapshot) => $presenter->snapshotPayload($snapshot))
                ->values(),
        ]);
    }

    public function close(Request $request, Tenant $tenant, InventoryCutoff $cutoff, InventoryService $inventory, InventoryController $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'inventory.snapshot.create');
        $this->assertBelongsToTenant($tenant, $cutoff);

        $cutoff = $inventory->closeCutoff($tenant, $cutoff, $request->user());

        return ApiResponse::success($presenter->cutoffPayload($cutoff->load(['location', 'branch'])));
    }
}
