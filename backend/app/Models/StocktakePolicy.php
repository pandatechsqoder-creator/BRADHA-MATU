<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class StocktakePolicy extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'priority_multipliers' => 'array',
            'deposit_policy_overrides' => 'array',
            'base_charge' => 'float',
            'per_sku_charge' => 'float',
            'per_location_charge' => 'float',
            'per_branch_charge' => 'float',
            'tax_rate' => 'float',
            'deposit_value' => 'float',
            'deposit_minimum' => 'float',
            'deposit_maximum' => 'float',
            'cancellation_fee_percent' => 'float',
        ];
    }
}
