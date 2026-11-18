<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discrepancy extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'original_quantity' => 'decimal:6',
            'verified_quantity' => 'decimal:6',
            'variance' => 'decimal:6',
            'resolved_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo { return $this->belongsTo(StocktakeJob::class, 'job_id'); }
    public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class); }
    public function finding(): BelongsTo { return $this->belongsTo(QaFinding::class, 'qa_finding_id'); }
    public function evidence(): HasMany { return $this->hasMany(DiscrepancyEvidence::class, 'discrepancy_id'); }
}
