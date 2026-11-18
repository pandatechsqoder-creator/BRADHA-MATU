<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleBusinessLocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['address' => 'array', 'insights' => 'array', 'synced_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GoogleBusinessAccount::class, 'account_id');
    }
}
