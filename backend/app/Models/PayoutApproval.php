<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutApproval extends Model
{
    use HasPublicId;

    protected $guarded = [];

    public function batch(): BelongsTo { return $this->belongsTo(PayoutBatch::class, 'batch_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approver_id'); }
}
