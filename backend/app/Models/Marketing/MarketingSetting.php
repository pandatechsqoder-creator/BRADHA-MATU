<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;

class MarketingSetting extends Model
{
    protected $table = 'marketing_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'is_public' => 'boolean',
        ];
    }
}
