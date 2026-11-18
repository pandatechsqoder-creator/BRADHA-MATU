<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;

class TrackingPreference extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'necessary' => 'boolean',
            'analytics' => 'boolean',
            'marketing' => 'boolean',
        ];
    }
}
