<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantResultReview extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['review_window_expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function job(): BelongsTo { return $this->belongsTo(StocktakeJob::class, 'job_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function items(): HasMany { return $this->hasMany(TenantResultReviewItem::class, 'tenant_result_review_id'); }
    public function disputes(): HasMany { return $this->hasMany(StocktakingDispute::class, 'tenant_result_review_id'); }
}
