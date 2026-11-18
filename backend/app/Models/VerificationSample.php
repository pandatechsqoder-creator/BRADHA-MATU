<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationSample extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'original_quantity' => 'decimal:6',
            'verified_quantity' => 'decimal:6',
            'variance' => 'decimal:6',
            'verified_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo { return $this->belongsTo(VerificationTask::class, 'verification_task_id'); }
    public function entry(): BelongsTo { return $this->belongsTo(CountEntry::class, 'entry_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
}
