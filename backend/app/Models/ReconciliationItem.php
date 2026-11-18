<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $casts = [
        'counted_quantity' => 'decimal:6',
        'expected_quantity' => 'decimal:6',
        'variance' => 'decimal:6',
    ];

    public function report(): BelongsTo { return $this->belongsTo(ReconciliationReport::class, 'reconciliation_report_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
}
