<?php

namespace App\Services\Tenancy;

use App\Models\Branch;
use App\Models\StockLocation;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DefaultHeadOfficeProvisioner
{
    public function provision(Tenant $tenant): array
    {
        return DB::transaction(function () use ($tenant): array {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $branch = $this->findDefaultBranch($tenant) ?? $this->findHeadOfficeBranch($tenant);

            if ($branch === null) {
                $branch = Branch::create([
                    'tenant_id' => $tenant->id,
                    'name' => 'Head Office',
                    'code' => 'HEAD-OFFICE',
                    'timezone' => $tenant->timezone ?: 'Africa/Nairobi',
                    'status' => 'active',
                ]);
            }

            return $this->makeDefaultBranch($tenant, $branch);
        });
    }

    public function setDefaultBranch(Tenant $tenant, Branch $branch): array
    {
        return DB::transaction(function () use ($tenant, $branch): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if ($branch->tenant_id !== $tenant->id) {
                abort(404);
            }

            if ($branch->status !== 'active') {
                throw ValidationException::withMessages([
                    'is_default' => ['An inactive branch cannot be the default.'],
                ]);
            }

            return $this->makeDefaultBranch($tenant, $branch);
        });
    }

    public function setDefaultLocation(Tenant $tenant, StockLocation $location): array
    {
        return DB::transaction(function () use ($tenant, $location): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if ($location->tenant_id !== $tenant->id) {
                abort(404);
            }

            if ($location->status !== 'active') {
                throw ValidationException::withMessages([
                    'is_default' => ['An inactive stock location cannot be the default.'],
                ]);
            }

            if ($location->branch_id === null) {
                throw ValidationException::withMessages([
                    'is_default' => ['Assign the stock location to a branch before making it the default.'],
                ]);
            }

            $branch = Branch::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey($location->branch_id)
                ->firstOrFail();

            if ($branch->status !== 'active') {
                throw ValidationException::withMessages([
                    'is_default' => ['The stock location must belong to an active branch.'],
                ]);
            }

            Branch::query()->where('tenant_id', $tenant->id)->update(['is_default' => false]);
            $branch->update(['is_default' => true]);
            StockLocation::query()->where('tenant_id', $tenant->id)->update(['is_default' => false]);
            $location->update(['is_default' => true]);

            return ['branch' => $branch->fresh(), 'location' => $location->fresh()];
        });
    }

    private function makeDefaultBranch(Tenant $tenant, Branch $branch): array
    {
        Branch::query()->where('tenant_id', $tenant->id)->update(['is_default' => false]);
        $branch->update(['is_default' => true]);

        $location = StockLocation::query()
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();

        $location ??= StockLocation::query()
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->where('name', 'Main Store')
            ->orderBy('id')
            ->first();

        $location ??= StockLocation::query()
            ->where('tenant_id', $tenant->id)
            ->where('branch_id', $branch->id)
            ->orderBy('id')
            ->first();

        if ($location === null) {
            $location = StockLocation::create([
                'tenant_id' => $tenant->id,
                'branch_id' => $branch->id,
                'name' => 'Main Store',
                'code' => 'MAIN',
                'type' => 'zone',
                'status' => 'active',
            ]);
        }

        if ($location->status !== 'active') {
            $location->update(['status' => 'active']);
        }

        StockLocation::query()->where('tenant_id', $tenant->id)->update(['is_default' => false]);
        $location->update(['is_default' => true]);

        return ['branch' => $branch->fresh(), 'location' => $location->fresh()];
    }

    private function findDefaultBranch(Tenant $tenant): ?Branch
    {
        return Branch::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_default', true)
            ->orderBy('id')
            ->first();
    }

    private function findHeadOfficeBranch(Tenant $tenant): ?Branch
    {
        return Branch::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query): void {
                $query->where('code', 'HEAD-OFFICE')
                    ->orWhereRaw('LOWER(name) = ?', ['head office']);
            })
            ->orderBy('id')
            ->first();
    }
}
