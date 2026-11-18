<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;

class GoogleSearchMetric extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'ctr' => 'float',
            'position' => 'float',
        ];
    }
}
