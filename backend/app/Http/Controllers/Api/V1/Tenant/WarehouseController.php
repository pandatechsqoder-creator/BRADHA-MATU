<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class WarehouseController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.view');

        $query = Warehouse::query()
            ->where('tenant_id', $tenant->id)
            ->withCount('stockLocations')
            ->orderBy('name');

        if ($branchUuid = $request->string('branch')->trim()->toString()) {
            $branch = Branch::where('tenant_id', $tenant->id)->where('uuid', $branchUuid)->first();
            $query->where('branch_id', $branch?->id ?? 0);
        }

        if (($scopeIds = $request->user()->branchScopeIds($tenant->id)) !== null) {
            $query->whereIn('branch_id', $scopeIds);
        }

        $branches = Branch::where('tenant_id', $tenant->id)->get(['id', 'uuid', 'name'])->keyBy('id');

        return ApiResponse::success($query->get()->map(fn (Warehouse $warehouse) => [
            'id' => $warehouse->uuid,
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'address' => $warehouse->address,
            'status' => $warehouse->status,
            'branch_id' => $warehouse->branch_id ? $branches->get($warehouse->branch_id)?->uuid : null,
            'branch_name' => $warehouse->branch_id ? $branches->get($warehouse->branch_id)?->name : null,
            'locations_count' => $warehouse->stock_locations_count ?? 0,
        ])->values());
    }

    public function store(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'string'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $branch = $this->resolveBranch($tenant, $data['branch_id'] ?? null);

        $warehouse = Warehouse::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch?->id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'address' => $data['address'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        $audit->log('warehouse.created', $warehouse, null, null, $warehouse->only(['name', 'code']), $tenant->id);

        return ApiResponse::success([
            'id' => $warehouse->uuid,
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'status' => $warehouse->status,
            'branch_id' => $branch?->uuid,
            'branch_name' => $branch?->name,
            'locations_count' => 0,
        ], 201);
    }

    public function update(Request $request, Tenant $tenant, Warehouse $warehouse, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $warehouse);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'string'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        if (array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $this->resolveBranch($tenant, $data['branch_id'])?->id;
        }

        $before = $warehouse->only(array_keys($data));
        $warehouse->update($data);

        if ($warehouse->wasChanged()) {
            $audit->log('warehouse.updated', $warehouse, null, $before, $warehouse->only(array_keys($data)), $tenant->id);
        }

        return ApiResponse::success(['id' => $warehouse->uuid, 'name' => $warehouse->name, 'status' => $warehouse->status]);
    }

    public function destroy(Request $request, Tenant $tenant, Warehouse $warehouse, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $warehouse);

        $warehouse->update(['status' => 'inactive']);
        $audit->log('warehouse.deactivated', $warehouse, null, null, ['status' => 'inactive'], $tenant->id);

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
}
