<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $table = 'units_of_measure';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'factor_to_base' => 'decimal:6',
            'is_base' => 'boolean',
        ];
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(UnitConversion::class, 'from_unit_id');
    }
}
