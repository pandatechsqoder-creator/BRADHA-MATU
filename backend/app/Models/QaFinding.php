<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QaFinding extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'counted_quantity' => 'decimal:6',
            'verified_quantity' => 'decimal:6',
            'variance' => 'decimal:6',
        ];
    }

    public function review(): BelongsTo { return $this->belongsTo(QaReview::class, 'qa_review_id'); }
    public function entry(): BelongsTo { return $this->belongsTo(CountEntry::class, 'entry_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
}
