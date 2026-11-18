<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;

class Ga4Metric extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sessions' => 'integer',
            'total_users' => 'integer',
            'conversions' => 'float',
        ];
    }
}
