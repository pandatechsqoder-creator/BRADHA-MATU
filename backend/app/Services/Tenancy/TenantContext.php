<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves the active tenant server-side from authenticated membership.
 * A client-supplied X-Tenant-Id is only honoured when the user is a member
 * of that tenant (or a platform admin, whose cross-tenant access is audited).
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $resolved = false;

    public function __construct(private readonly Request $request) {}

    public function tenant(): ?Tenant
    {
        if (! $this->resolved) {
            $this->tenant = $this->resolve();
            $this->resolved = true;
        }

        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant()?->id;
    }

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->resolved = true;
    }

    private function resolve(): ?Tenant
    {
        /** @var User|null $user */
        $user = $this->request->user();
        if (! $user) {
            return null;
        }

        $requested = $this->request->headers->get('X-Tenant-Id');
        $hostTenantId = $this->request->attributes->get('tenant_host_id');

        if ($hostTenantId !== null) {
            $tenant = Tenant::find($hostTenantId);
            if ($tenant === null) {
                return null;
            }

            if ($requested !== null) {
                $requestedTenant = Tenant::where('uuid', $requested)->first();
                if ($requestedTenant?->id !== $tenant->id) {
                    return null;
                }
            }

            return $user->isMemberOf($tenant) || $user->isPlatformAdmin()
                ? $tenant
                : null;
        }

        if ($requested !== null) {
            $tenant = Tenant::where('uuid', $requested)->first();
            if (! $tenant) {
                return null;
            }
            if ($user->isMemberOf($tenant) || $user->isPlatformAdmin()) {
                return $tenant;
            }

            return null;
        }

        $memberships = $user->activeTenants();
        if ($memberships->count() === 1) {
            return $memberships->first();
        }

        return null;
    }
}