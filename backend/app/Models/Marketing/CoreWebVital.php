<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;

class CoreWebVital extends Model
{
    protected $table = 'seo_core_web_vitals';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lcp' => 'float',
            'cls' => 'float',
            'inp' => 'float',
            'ttfb' => 'float',
            'metric' => 'array',
            'measured_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
