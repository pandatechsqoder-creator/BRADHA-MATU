<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CataloguePricingPolicy extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'base_charge' => 'decimal:2',
            'max_charge' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CataloguePricingVersion::class, 'policy_id')->orderByDesc('version');
    }
}
