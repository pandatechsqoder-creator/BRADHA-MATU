<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTakerAvailability extends Model
{
    use HasPublicId;

    protected $table = 'stock_taker_availability';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(StockTakerProfile::class, 'profile_id');
    }
}
