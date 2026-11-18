<?php

namespace App\Services\Catalogue;

use App\Models\Catalogue;
use App\Models\CatalogueVersion;
use App\Models\ProductVariant;
use App\Models\User;

final class CatalogueVersionService
{
    public function publish(Catalogue $catalogue, ?User $actor, string $source = 'manual', ?string $notes = null): CatalogueVersion
    {
        $productCount = ProductVariant::query()
            ->where('tenant_id', $catalogue->tenant_id)
            ->where('status', 'active')
            ->count();

        $lastVersion = (int) CatalogueVersion::query()
            ->where('catalogue_id', $catalogue->id)
            ->max('version_number');

        CatalogueVersion::query()
            ->where('catalogue_id', $catalogue->id)
            ->where('status', 'active')
            ->update(['status' => 'superseded']);

        $version = CatalogueVersion::create([
            'tenant_id' => $catalogue->tenant_id,
            'catalogue_id' => $catalogue->id,
            'version_number' => $lastVersion + 1,
            'status' => 'active',
            'product_count' => $productCount,
            'source' => $source,
            'created_by' => $actor?->id,
            'notes' => $notes,
        ]);

        $catalogue->update([
            'current_version_id' => $version->id,
            'status' => 'active',
            'confirmed_at' => null,
        ]);

        return $version;
    }
}
