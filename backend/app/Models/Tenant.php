<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasPublicId;

    protected $guarded = [];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_memberships')
            ->withPivot(['status', 'joined_at'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_memberships')
            ->withPivot(['status', 'joined_at'])
            ->withTimestamps();
    }

    public function stockZones(): HasMany
    {
        return $this->hasMany(StockZone::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function branchScopes(): HasMany
    {
        return $this->hasMany(UserBranchScope::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function stockLocations(): HasMany
    {
        return $this->hasMany(StockLocation::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function catalogues(): HasMany
    {
        return $this->hasMany(Catalogue::class);
    }

    public function catalogueImports(): HasMany
    {
        return $this->hasMany(CatalogueImport::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function cutoffs(): HasMany
    {
        return $this->hasMany(InventoryCutoff::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(InventorySnapshot::class);
    }

    public function stocktakeJobs(): HasMany
    {
        return $this->hasMany(StocktakeJob::class);
    }

    /**
     * Named for nested route model binding (/tenants/{tenant}/stocktake-jobs/{job}).
     */
    public function jobs(): HasMany
    {
        return $this->stocktakeJobs();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $setting = $this->settings->firstWhere('key', $key);

        return $setting ? $setting->value : $default;
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(PosTerminal::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(PosShift::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StocktakeSchedule::class, 'tenant_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(ScheduleOccurrence::class, 'tenant_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }
}