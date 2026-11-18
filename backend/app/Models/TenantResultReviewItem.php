<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantResultReviewItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $casts = [
        'counted_quantity' => 'decimal:6',
        'expected_quantity' => 'decimal:6',
        'variance' => 'decimal:6',
    ];

    public function review(): BelongsTo { return $this->belongsTo(TenantResultReview::class, 'tenant_result_review_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
}
