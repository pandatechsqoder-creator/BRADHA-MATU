<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosTransferItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(PosTransfer::class, 'transfer_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
