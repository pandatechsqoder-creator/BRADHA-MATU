<?php

namespace App\Http\Controllers\Api\V1\Tenant\Concerns;

use App\Models\Tenant;
use Illuminate\Http\Request;

trait ChecksTenantAccess
{
    protected function assertCan(Request $request, Tenant $tenant, string $permission): void
    {
        abort_unless($request->user()?->isMemberOf($tenant), 403, 'You are not a member of this organisation.');
        abort_unless($request->user()->hasPermission($permission, $tenant->id), 403, 'You are not allowed to perform this action.');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function assertAnyCan(Request $request, Tenant $tenant, array $permissions): void
    {
        abort_unless($request->user()?->isMemberOf($tenant), 403, 'You are not a member of this organisation.');

        foreach ($permissions as $permission) {
            if ($request->user()->hasPermission($permission, $tenant->id)) {
                return;
            }
        }

        abort(403, 'You are not allowed to perform this action.');
    }

    protected function assertBelongsToTenant(Tenant $tenant, ?object $model): void
    {
        abort_if($model === null || $model->tenant_id !== $tenant->id, 404);
    }
}
