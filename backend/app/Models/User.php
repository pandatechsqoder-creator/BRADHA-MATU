<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->uuid ??= (string) Str::ulid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function stockTakerProfile(): HasOne
    {
        return $this->hasOne(StockTakerProfile::class);
    }

    public function branchScopes(): HasMany
    {
        return $this->hasMany(UserBranchScope::class);
    }

    /**
     * Null means unscoped (all tenant branches); otherwise the allowed branch IDs.
     *
     * @return list<int>|null
     */
    public function branchScopeIds(int $tenantId): ?array
    {
        $ids = $this->branchScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('branch_id')
            ->all();

        return $ids === [] ? null : $ids;
    }

    public function activeTenants(): Collection
    {
        return Tenant::whereIn('id', $this->memberships()->where('status', 'active')->pluck('tenant_id'))->get();
    }

    public function isMemberOf(Tenant $tenant): bool
    {
        return $this->memberships()->where('tenant_id', $tenant->id)->where('status', 'active')->exists();
    }

    public function isPlatformAdmin(): bool
    {
        return $this->roles()->where('slug', 'super-admin')->whereNull('user_roles.tenant_id')->exists();
    }

    public function hasPlatformRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->whereNull('user_roles.tenant_id')->exists();
    }

    /**
     * Effective permission slugs, optionally scoped to a tenant.
     * Platform roles apply globally; tenant roles only within their tenant.
     */
    public function permissionSlugs(?int $tenantId = null): Collection
    {
        $roleIds = $this->roles()
            ->where(function ($q) use ($tenantId): void {
                $q->whereNull('user_roles.tenant_id');
                if ($tenantId !== null) {
                    $q->orWhere('user_roles.tenant_id', $tenantId);
                }
            })
            ->pluck('roles.id');

        $granted = Permission::whereIn('id', function ($q) use ($roleIds): void {
            $q->select('permission_id')->from('role_permissions')->whereIn('role_id', $roleIds);
        })->pluck('slug');

        $overrides = UserPermissionOverride::where('user_id', $this->id)
            ->where(function ($q) use ($tenantId): void {
                $q->whereNull('tenant_id');
                if ($tenantId !== null) {
                    $q->orWhere('tenant_id', $tenantId);
                }
            })
            ->with('permission')
            ->get();

        $granted = $granted->merge(
            $overrides->where('effect', 'grant')->map(fn ($o) => $o->permission->slug)
        )->unique()->values();

        $denied = $overrides->where('effect', 'deny')->map(fn ($o) => $o->permission->slug);

        return $granted->reject(fn ($slug) => $denied->contains($slug))->values();
    }

    public function hasPermission(string $slug, ?int $tenantId = null): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        return $this->permissionSlugs($tenantId)->contains($slug);
    }

    public function assignRole(string $slug, ?int $tenantId = null): void
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        $this->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => $tenantId]]);
    }
}