<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Catalogue extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CatalogueVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogueVersion::class, 'current_version_id');
    }

    public function declarations(): HasMany
    {
        return $this->hasMany(CatalogueDeclaration::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(CatalogueInvoice::class);
    }
}
