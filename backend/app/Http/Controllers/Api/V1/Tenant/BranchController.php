<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\DefaultHeadOfficeProvisioner;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BranchController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.view');

        $query = Branch::query()
            ->where('tenant_id', $tenant->id)
            ->withCount(['departments', 'warehouses', 'stockLocations'])
            ->orderBy('name');

        if (($scopeIds = $request->user()->branchScopeIds($tenant->id)) !== null) {
            $query->whereIn('id', $scopeIds);
        }

        return ApiResponse::success($query->get()->map(fn (Branch $branch) => $this->payload($branch))->values());
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
            'code' => ['nullable', 'string', 'max:40', Rule::unique('branches')->where('tenant_id', $tenant->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $branch = DB::transaction(function () use ($data, $request, $tenant, $headOffice): Branch {
            $branch = Branch::create([
                ...$data,
                'tenant_id' => $tenant->id,
                'created_by' => $request->user()->id,
                'timezone' => $data['timezone'] ?? $tenant->timezone,
                'status' => $data['status'] ?? 'active',
                'is_default' => false,
            ]);

            if ($data['is_default'] ?? false) {
                $headOffice->setDefaultBranch($tenant, $branch);
            }

            return $branch->fresh();
        });

        $audit->log('branch.created', $branch, null, null, $branch->only(['name', 'code', 'status']), $tenant->id);

        return ApiResponse::success($this->payload($branch->loadCount(['departments', 'warehouses', 'stockLocations'])), 201);
    }

    public function update(
        Request $request,
        Tenant $tenant,
        Branch $branch,
        AuditLogger $audit,
        DefaultHeadOfficeProvisioner $headOffice,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $branch);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('branches')->where('tenant_id', $tenant->id)->ignore($branch->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_default'] ?? null) === false && $branch->is_default) {
            throw ValidationException::withMessages([
                'is_default' => ['Choose another default branch before clearing this one.'],
            ]);
        }

        if (($data['status'] ?? null) === 'inactive' && $branch->is_default && ! ($data['is_default'] ?? false)) {
            throw ValidationException::withMessages([
                'status' => ['Choose another default branch before deactivating this one.'],
            ]);
        }

        $before = $branch->only(array_keys($data));
        DB::transaction(function () use ($branch, $data, $tenant, $headOffice): void {
            $makeDefault = (bool) ($data['is_default'] ?? false);
            unset($data['is_default']);
            $branch->update($data);

            if ($makeDefault) {
                $headOffice->setDefaultBranch($tenant, $branch->fresh());
            }
        });
        $branch->refresh();

        if ($branch->wasChanged()) {
            $audit->log('branch.updated', $branch, null, $before, $branch->only(array_keys($data)), $tenant->id);
        }

        return ApiResponse::success($this->payload($branch->loadCount(['departments', 'warehouses', 'stockLocations'])));
    }

    public function destroy(Request $request, Tenant $tenant, Branch $branch, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $branch);

        if ($branch->is_default) {
            throw ValidationException::withMessages([
                'branch' => ['Choose another default branch before deactivating this one.'],
            ]);
        }

        $branch->update(['status' => 'inactive']);
        $audit->log('branch.deactivated', $branch, 'Branch marked inactive; history preserved.', null, ['status' => 'inactive'], $tenant->id);

        return ApiResponse::success(['status' => 'inactive']);
    }

    private function payload(Branch $branch): array
    {
        return [
            'id' => $branch->uuid,
            'name' => $branch->name,
            'code' => $branch->code,
            'address' => $branch->address,
            'city' => $branch->city,
            'phone' => $branch->phone,
            'email' => $branch->email,
            'timezone' => $branch->timezone,
            'status' => $branch->status,
            'is_default' => (bool) $branch->is_default,
            'departments_count' => $branch->departments_count ?? 0,
            'warehouses_count' => $branch->warehouses_count ?? 0,
            'locations_count' => $branch->stock_locations_count ?? 0,
            'created_at' => $branch->created_at?->toIso8601String(),
        ];
    }
}
