<?php

namespace App\Http\Resources;

use App\Models\Permission;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenantContext = app(TenantContext::class);

        $roles = $this->roles()->get();
        $memberships = $this->memberships()
            ->with('tenant')
            ->where('status', 'active')
            ->get();

        $currentTenant = $tenantContext->tenant()
            ?? ($memberships->count() === 1 ? $memberships->first()->tenant : null);
        $currentTenantId = $currentTenant?->id;

        $tenants = $memberships
            ->map(fn ($membership) => [
                'id' => $membership->tenant->uuid,
                'name' => $membership->tenant->name,
                'status' => $membership->tenant->status,
                'operating_mode' => $membership->tenant->operating_mode,
                'roles' => $this->roles()
                    ->wherePivot('tenant_id', $membership->tenant_id)
                    ->pluck('slug')
                    ->values(),
            ])
            ->values();

        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'must_change_password' => (bool) $this->must_change_password,
            'created_at' => $this->created_at->toIso8601String(),
            'roles' => $roles
                ->map(fn ($role) => [
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'scope' => $role->scope,
                    'tenant_id' => $role->pivot->tenant_id
                        ? $memberships->firstWhere('tenant_id', $role->pivot->tenant_id)?->tenant?->uuid
                        : null,
                ])
                ->values(),
            'permissions' => ($this->isPlatformAdmin()
                ? Permission::query()->pluck('slug')
                : $this->permissionSlugs($currentTenantId))->unique()->values(),
            'tenants' => $tenants,
            'current_tenant' => $currentTenant ? [
                'id' => $currentTenant->uuid,
                'name' => $currentTenant->name,
                'operating_mode' => $currentTenant->operating_mode,
            ] : null,
        ];
    }
}
