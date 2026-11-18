<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

final class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->isMemberOf($tenant) || $user->isPlatformAdmin();
    }

    public function update(User $user, Tenant $tenant): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $user->isMemberOf($tenant) && $user->hasPermission('tenant.update', $tenant->id);
    }

    public function manageMembers(User $user, Tenant $tenant): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return $user->isMemberOf($tenant) && $user->hasPermission('tenant.users.manage', $tenant->id);
    }
}
