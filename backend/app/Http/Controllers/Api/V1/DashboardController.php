<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $user = $request->user();
        $tenant = $tenantContext->tenant();

        $platformRoles = $user->roles()
            ->where('scope', 'platform')
            ->whereNull('user_roles.tenant_id')
            ->pluck('slug')
            ->values();
        $workerRoles = $user->roles()
            ->whereIn('scope', ['workforce'])
            ->pluck('slug')
            ->values();
        $tenantRoles = $tenant
            ? $user->roles()->wherePivot('tenant_id', $tenant->id)->pluck('slug')->values()
            : collect();

        $catalogue = $tenant
            ? \App\Models\Catalogue::query()->where('tenant_id', $tenant->id)->latest('id')->first()
            : null;
        $catalogueReady = $catalogue !== null
            && $catalogue->confirmed_at !== null
            && $catalogue->currentVersion !== null
            && $catalogue->currentVersion->product_count > 0;

        return ApiResponse::success([
            'workspace' => match (true) {
                $platformRoles->isNotEmpty() => 'platform',
                $workerRoles->isNotEmpty() => 'workforce',
                $tenant !== null => 'tenant',
                default => 'onboarding',
            },
            'platform_roles' => $platformRoles,
            'tenant_roles' => $tenantRoles,
            'worker_roles' => $workerRoles,
            'tenant' => $tenant ? [
                'id' => $tenant->uuid,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'operating_mode' => $tenant->operating_mode,
            ] : null,
            'stats' => [
                'accessible_tenants' => $user->isPlatformAdmin()
                    ? \App\Models\Tenant::count()
                    : $user->memberships()->where('status', 'active')->count(),
                'tenant_members' => $tenant ? $tenant->memberships()->where('status', 'active')->count() : 0,
                'pending_invitations' => $tenant ? $tenant->invitations()->where('status', 'pending')->count() : 0,
                'unread_notifications' => 0,
                'branches' => $tenant ? $tenant->branches()->where('status', 'active')->count() : 0,
                'catalogue_products' => $catalogue?->currentVersion?->product_count ?? 0,
            ],
            'onboarding' => [
                'has_tenant' => $user->memberships()->where('status', 'active')->exists(),
                'email_verified' => $user->email_verified_at !== null,
                'catalogue_ready' => $catalogueReady,
            ],
        ]);
    }
}
