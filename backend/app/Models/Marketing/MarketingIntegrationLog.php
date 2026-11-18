<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;

class MarketingIntegrationLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'datetime'];
    }
}
