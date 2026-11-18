<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTakerPayout extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'float', 'processed_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function profile(): BelongsTo { return $this->belongsTo(StockTakerProfile::class); }
    public function job(): BelongsTo { return $this->belongsTo(StocktakeJob::class, 'job_id'); }
    public function assignment(): BelongsTo { return $this->belongsTo(JobAssignment::class); }
    public function earning(): BelongsTo { return $this->belongsTo(StockTakerEarning::class, 'earning_id'); }
    public function batch(): BelongsTo { return $this->belongsTo(PayoutBatch::class, 'batch_id'); }
}
