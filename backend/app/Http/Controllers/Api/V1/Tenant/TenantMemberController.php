<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantMemberController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('view', $tenant);

        $branches = Branch::where('tenant_id', $tenant->id)->get(['id', 'uuid', 'name'])->keyBy('id');
        $manageable = $request->user()->hasPermission('tenant.users.manage', $tenant->id);

        $members = $tenant->memberships()
            ->with('user')
            ->where('status', 'active')
            ->get()
            ->map(function ($membership) use ($tenant, $branches): array {
                $user = $membership->user;

                return [
                    'id' => $user->uuid,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $membership->status,
                    'joined_at' => $membership->joined_at?->toIso8601String(),
                    'roles' => $user->roles()
                        ->wherePivot('tenant_id', $tenant->id)
                        ->get()
                        ->map(fn ($role) => ['slug' => $role->slug, 'name' => $role->name])
                        ->values(),
                    'branch_scopes' => $user->branchScopes()
                        ->where('tenant_id', $tenant->id)
                        ->get()
                        ->map(function ($scope) use ($branches): ?array {
                            $branch = $branches->get($scope->branch_id);

                            return $branch ? ['id' => $branch->uuid, 'name' => $branch->name] : null;
                        })
                        ->filter()
                        ->values(),
                ];
            })
            ->values();

        return ApiResponse::success($members, 200, ['can_manage' => $manageable]);
    }

    public function update(Request $request, Tenant $tenant, User $user, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'tenant.users.manage');
        $this->assertActiveMember($tenant, $user);

        $data = $request->validate([
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string'],
            'branch_ids' => ['sometimes', 'nullable', 'array'],
            'branch_ids.*' => ['string'],
        ]);

        $roles = null;
        if (array_key_exists('roles', $data)) {
            $roles = Role::whereIn('slug', $data['roles'])->where('scope', 'tenant')->get();
            abort_if($roles->count() !== count(array_unique($data['roles'])), 422, 'One or more roles are not assignable to tenant users.');

            $currentlyOwner = $user->roles()->wherePivot('tenant_id', $tenant->id)->where('slug', 'business-owner')->exists();
            $stillOwner = $roles->contains('slug', 'business-owner');

            if ($currentlyOwner && ! $stillOwner) {
                abort_if($user->id === $request->user()->id, 422, 'You cannot remove your own Business Owner role.');
                $otherOwners = DB::table('user_roles')
                    ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                    ->join('tenant_memberships', 'tenant_memberships.user_id', '=', 'user_roles.user_id')
                    ->where('roles.slug', 'business-owner')
                    ->where('user_roles.tenant_id', $tenant->id)
                    ->where('tenant_memberships.tenant_id', $tenant->id)
                    ->where('tenant_memberships.status', 'active')
                    ->where('user_roles.user_id', '!=', $user->id)
                    ->exists();
                abort_unless($otherOwners, 422, 'The organisation must keep at least one Business Owner.');
            }
        }

        $branchIds = null;
        if (array_key_exists('branch_ids', $data)) {
            $uuids = $data['branch_ids'] ?? [];
            $branchIds = Branch::where('tenant_id', $tenant->id)->whereIn('uuid', $uuids)->pluck('id')->all();
            abort_if(count($branchIds) !== count(array_unique($uuids)), 422, 'One or more branches do not belong to this organisation.');
        }

        DB::transaction(function () use ($tenant, $user, $roles, $branchIds): void {
            if ($roles !== null) {
                DB::table('user_roles')->where('user_id', $user->id)->where('tenant_id', $tenant->id)->delete();
                foreach ($roles as $role) {
                    DB::table('user_roles')->insertOrIgnore([
                        'user_id' => $user->id,
                        'role_id' => $role->id,
                        'tenant_id' => $tenant->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($branchIds !== null) {
                DB::table('user_branch_scopes')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->delete();
                foreach ($branchIds as $branchId) {
                    DB::table('user_branch_scopes')->insertOrIgnore([
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'branch_id' => $branchId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        if ($roles !== null) {
            $audit->log('member.roles_updated', $user, null, null, ['roles' => $roles->pluck('slug')->all()], $tenant->id);
        }

        if ($branchIds !== null) {
            $audit->log('member.scopes_updated', $user, null, null, ['branch_count' => count($branchIds)], $tenant->id);
        }

        return ApiResponse::success($this->memberPayload($tenant, $user));
    }

    public function destroy(Request $request, Tenant $tenant, User $user, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'tenant.users.manage');
        $this->assertActiveMember($tenant, $user);
        abort_if($user->id === $request->user()->id, 422, 'You cannot remove your own membership.');

        $tenant->memberships()->where('user_id', $user->id)->update(['status' => 'removed']);
        DB::table('user_roles')->where('user_id', $user->id)->where('tenant_id', $tenant->id)->delete();
        DB::table('user_branch_scopes')->where('user_id', $user->id)->where('tenant_id', $tenant->id)->delete();

        $audit->log('member.removed', $user, null, null, ['email' => $user->email], $tenant->id);

        return ApiResponse::success(['status' => 'removed']);
    }

    private function assertActiveMember(Tenant $tenant, User $user): void
    {
        abort_unless(
            $tenant->memberships()->where('user_id', $user->id)->where('status', 'active')->exists(),
            404,
            'This user is not an active member of the organisation.'
        );
    }

    private function memberPayload(Tenant $tenant, User $user): array
    {
        return [
            'id' => $user->uuid,
            'name' => $user->name,
            'email' => $user->email,
            'status' => 'active',
            'roles' => $user->roles()->wherePivot('tenant_id', $tenant->id)->get()->map(fn ($role) => ['slug' => $role->slug, 'name' => $role->name])->values(),
            'branch_scopes' => $user->branchScopes()->where('tenant_id', $tenant->id)->get()->map(function ($scope): ?array {
                $branch = Branch::find($scope->branch_id);

                return $branch ? ['id' => $branch->uuid, 'name' => $branch->name] : null;
            })->filter()->values(),
        ];
    }
}
