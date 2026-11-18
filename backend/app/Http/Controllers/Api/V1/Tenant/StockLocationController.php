<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\DefaultHeadOfficeProvisioner;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StockLocationController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.view');

        $query = StockLocation::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('zones')
            ->orderBy('name');

        if ($branchUuid = $request->string('branch')->trim()->toString()) {
            $branch = Branch::where('tenant_id', $tenant->id)->where('uuid', $branchUuid)->first();
            $query->where('branch_id', $branch?->id ?? 0);
        }

        if ($warehouseUuid = $request->string('warehouse')->trim()->toString()) {
            $warehouse = Warehouse::where('tenant_id', $tenant->id)->where('uuid', $warehouseUuid)->first();
            $query->where('warehouse_id', $warehouse?->id ?? 0);
        }

        if (($scopeIds = $request->user()->branchScopeIds($tenant->id)) !== null) {
            $query->whereIn('branch_id', $scopeIds);
        }

        $branches = Branch::where('tenant_id', $tenant->id)->get(['id', 'uuid', 'name'])->keyBy('id');
        $warehouses = Warehouse::where('tenant_id', $tenant->id)->get(['id', 'uuid', 'name'])->keyBy('id');

        return ApiResponse::success($query->get()->map(fn (StockLocation $location) => [
            'id' => $location->uuid,
            'name' => $location->name,
            'code' => $location->code,
            'type' => $location->type,
            'status' => $location->status,
            'is_default' => (bool) $location->is_default,
            'branch_id' => $location->branch_id ? $branches->get($location->branch_id)?->uuid : null,
            'branch_name' => $location->branch_id ? $branches->get($location->branch_id)?->name : null,
            'warehouse_id' => $location->warehouse_id ? $warehouses->get($location->warehouse_id)?->uuid : null,
            'warehouse_name' => $location->warehouse_id ? $warehouses->get($location->warehouse_id)?->name : null,
            'zones_count' => $location->zones_count ?? 0,
        ])->values());
    }

    public function store(
        Request $request,
        Tenant $tenant,
        AuditLogger $audit,
        DefaultHeadOfficeProvisioner $headOffice,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'branch.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(['zone', 'shelf', 'aisle', 'floor', 'bin'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $branch = $this->resolveBranch($tenant, $data['branch_id'] ?? null);
        $warehouse = $this->resolveWarehouse($tenant, $data['warehouse_id'] ?? null);

        if ($warehouse !== null && $branch !== null && $warehouse->branch_id !== $branch->id) {
            abort(422, 'The selected warehouse does not belong to the selected branch.');
        }

        $location = DB::transaction(function () use ($data, $tenant, $branch, $warehouse, $headOffice): StockLocation {
            $location = StockLocation::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch?->id ?? $warehouse?->branch_id,
                'warehouse_id' => $warehouse?->id,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'type' => $data['type'] ?? 'zone',
                'status' => $data['status'] ?? 'active',
                'is_default' => false,
            ]);

            if ($data['is_default'] ?? false) {
                $headOffice->setDefaultLocation($tenant, $location);
                $location->refresh();
            }

            return $location;
        });

        $audit->log('stock_location.created', $location, null, null, $location->only(['name', 'code', 'type', 'is_default']), $tenant->id);

        return ApiResponse::success([
            'id' => $location->uuid,
            'name' => $location->name,
            'code' => $location->code,
            'type' => $location->type,
            'status' => $location->status,
            'is_default' => (bool) $location->is_default,
            'branch_id' => $branch?->uuid ?? $warehouse?->branch?->uuid,
            'warehouse_id' => $warehouse?->uuid,
            'zones_count' => 0,
        ], 201);
    }

    public function update(
        Request $request,
        Tenant $tenant,
        StockLocation $stockLocation,
        AuditLogger $audit,
        DefaultHeadOfficeProvisioner $headOffice,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $stockLocation);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'warehouse_id' => ['nullable', 'string'],
            'type' => ['nullable', Rule::in(['zone', 'shelf', 'aisle', 'floor', 'bin'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_default'] ?? null) === false && $stockLocation->is_default) {
            throw ValidationException::withMessages([
                'is_default' => ['Choose another default stock location before clearing this one.'],
            ]);
        }

        if (($data['status'] ?? null) === 'inactive' && $stockLocation->is_default && ! ($data['is_default'] ?? false)) {
            throw ValidationException::withMessages([
                'status' => ['Choose another default stock location before deactivating this one.'],
            ]);
        }

        $wasDefault = (bool) $stockLocation->is_default;
        $before = $stockLocation->only(array_keys($data));
        DB::transaction(function () use ($data, $tenant, $stockLocation, $headOffice): void {
            if (array_key_exists('warehouse_id', $data)) {
                $warehouse = $this->resolveWarehouse($tenant, $data['warehouse_id']);
                $data['warehouse_id'] = $warehouse?->id;
                if ($warehouse !== null) {
                    $data['branch_id'] = $warehouse->branch_id;
                }
            }

            $makeDefault = (bool) ($data['is_default'] ?? false);
            unset($data['is_default']);
            $stockLocation->update($data);

            if ($makeDefault || $stockLocation->is_default) {
                $headOffice->setDefaultLocation($tenant, $stockLocation->fresh());
            }
        });
        $stockLocation->refresh();

        if ($stockLocation->wasChanged() || $wasDefault !== (bool) $stockLocation->is_default) {
            $audit->log('stock_location.updated', $stockLocation, null, $before, $stockLocation->only(array_keys($data)), $tenant->id);
        }

        return ApiResponse::success([
            'id' => $stockLocation->uuid,
            'name' => $stockLocation->name,
            'status' => $stockLocation->status,
            'is_default' => (bool) $stockLocation->is_default,
        ]);
    }

    public function destroy(Request $request, Tenant $tenant, StockLocation $stockLocation, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $stockLocation);

        if ($stockLocation->is_default) {
            throw ValidationException::withMessages([
                'stock_location' => ['Choose another default stock location before deactivating this one.'],
            ]);
        }

        $stockLocation->update(['status' => 'inactive']);
        $audit->log('stock_location.deactivated', $stockLocation, null, null, ['status' => 'inactive'], $tenant->id);

        return ApiResponse::success(['status' => 'inactive']);
    }

    private function resolveBranch(Tenant $tenant, ?string $uuid): ?Branch
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $branch = Branch::where('tenant_id', $tenant->id)->where('uuid', $uuid)->first();
        abort_if($branch === null, 422, 'The selected branch does not belong to this organisation.');

        return $branch;
    }

    private function resolveWarehouse(Tenant $tenant, ?string $uuid): ?Warehouse
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $warehouse = Warehouse::where('tenant_id', $tenant->id)->where('uuid', $uuid)->first();
        abort_if($warehouse === null, 422, 'The selected warehouse does not belong to this organisation.');

        return $warehouse;
    }
}
