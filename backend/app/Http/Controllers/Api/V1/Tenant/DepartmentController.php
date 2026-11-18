<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DepartmentController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.view');

        $query = Department::query()->where('tenant_id', $tenant->id)->orderBy('name');

        if ($branchUuid = $request->string('branch')->trim()->toString()) {
            $branch = Branch::where('tenant_id', $tenant->id)->where('uuid', $branchUuid)->first();
            $query->where('branch_id', $branch?->id ?? 0);
        }

        if (($scopeIds = $request->user()->branchScopeIds($tenant->id)) !== null) {
            $query->whereIn('branch_id', $scopeIds);
        }

        $branches = Branch::where('tenant_id', $tenant->id)->get(['id', 'uuid', 'name'])->keyBy('id');

        return ApiResponse::success($query->get()->map(fn (Department $department) => [
            'id' => $department->uuid,
            'name' => $department->name,
            'code' => $department->code,
            'status' => $department->status,
            'branch_id' => $department->branch_id ? $branches->get($department->branch_id)?->uuid : null,
            'branch_name' => $department->branch_id ? $branches->get($department->branch_id)?->name : null,
        ])->values());
    }

    public function store(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.update');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $branch = $this->resolveBranch($tenant, $data['branch_id'] ?? null);

        $department = Department::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch?->id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        $audit->log('department.created', $department, null, null, $department->only(['name', 'code']), $tenant->id);

        return ApiResponse::success([
            'id' => $department->uuid,
            'name' => $department->name,
            'code' => $department->code,
            'status' => $department->status,
            'branch_id' => $branch?->uuid,
            'branch_name' => $branch?->name,
        ], 201);
    }

    public function update(Request $request, Tenant $tenant, Department $department, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $department);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        if (array_key_exists('branch_id', $data)) {
            $data['branch_id'] = $this->resolveBranch($tenant, $data['branch_id'])?->id;
        }

        $before = $department->only(array_keys($data));
        $department->update($data);

        if ($department->wasChanged()) {
            $audit->log('department.updated', $department, null, $before, $department->only(array_keys($data)), $tenant->id);
        }

        return ApiResponse::success(['id' => $department->uuid, 'name' => $department->name, 'status' => $department->status]);
    }

    public function destroy(Request $request, Tenant $tenant, Department $department, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $department);

        $department->update(['status' => 'inactive']);
        $audit->log('department.deactivated', $department, null, null, ['status' => 'inactive'], $tenant->id);

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
