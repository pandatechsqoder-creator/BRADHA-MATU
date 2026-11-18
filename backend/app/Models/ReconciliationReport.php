<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationReport extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $casts = ['generated_at' => 'datetime'];

    public function job(): BelongsTo { return $this->belongsTo(StocktakeJob::class, 'job_id'); }
    public function review(): BelongsTo { return $this->belongsTo(TenantResultReview::class, 'tenant_result_review_id'); }
    public function items(): HasMany { return $this->hasMany(ReconciliationItem::class, 'reconciliation_report_id'); }
    public function adjustments(): HasMany { return $this->hasMany(ReconciliationAdjustment::class, 'reconciliation_report_id'); }
}
