<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;

class GtmContainer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_published_at' => 'datetime'];
    }
}
