<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleSearchProperty extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(GoogleSearchMetric::class, 'property_id');
    }
}
