<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeDecision extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $casts = ['conditions' => 'array'];

    public function dispute(): BelongsTo { return $this->belongsTo(StocktakingDispute::class, 'dispute_id'); }
    public function decidedBy(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
