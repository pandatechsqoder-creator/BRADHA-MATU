<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationAdjustment extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:6',
        'value' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function report(): BelongsTo { return $this->belongsTo(ReconciliationReport::class, 'reconciliation_report_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
