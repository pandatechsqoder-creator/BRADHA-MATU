<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountSessionLocation extends Model
{
    use HasPublicId;

    protected $guarded = [];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CountSession::class, 'session_id');
    }

    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
