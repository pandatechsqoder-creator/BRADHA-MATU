<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountException extends Model
{
    use HasPublicId;

    protected $guarded = [];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CountSession::class, 'session_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function observedUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'observed_unit_id');
    }
}
