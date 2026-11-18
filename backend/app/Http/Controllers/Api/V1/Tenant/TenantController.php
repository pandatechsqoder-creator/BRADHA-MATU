<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\DefaultHeadOfficeProvisioner;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $tenants = $user->isPlatformAdmin()
            ? Tenant::query()->withCount(['members', 'branches'])->latest()->get()
            : Tenant::query()
                ->whereIn('id', $user->memberships()->where('status', 'active')->pluck('tenant_id'))
                ->withCount(['members', 'branches'])
                ->latest()
                ->get();

        return ApiResponse::success(TenantResource::collection($tenants)->resolve());
    }

    public function store(
        StoreTenantRequest $request,
        AuditLogger $audit,
        DefaultHeadOfficeProvisioner $headOffice,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $tenant = DB::transaction(function () use ($request, $user, $headOffice): Tenant {
            $tenant = Tenant::create([
                ...$request->validated(),
                'created_by' => $user->id,
                'status' => 'active',
                'timezone' => $request->input('timezone', 'Africa/Nairobi'),
            ]);

            $headOffice->provision($tenant);

            $tenant->memberships()->create([
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $user->assignRole('business-owner', $tenant->id);

            return $tenant;
        });

        $audit->log('tenant.created', $tenant, null, null, ['name' => $tenant->name], $tenant->id);

        return ApiResponse::success(new TenantResource($tenant->loadCount('members')), 201);
    }

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        return ApiResponse::success(new TenantResource($tenant->loadCount(['members', 'branches'])));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $tenant);

        $before = $tenant->only(array_keys($request->validated()));
        $tenant->update($request->validated());

        if ($tenant->wasChanged()) {
            $audit->log('tenant.updated', $tenant, null, $before, $tenant->only(array_keys($request->validated())), $tenant->id);
        }

        return ApiResponse::success(new TenantResource($tenant));
    }
}
