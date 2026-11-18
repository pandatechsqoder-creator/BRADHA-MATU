<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscrepancyEvidence extends Model
{
    use HasPublicId;

    protected $guarded = [];

    public function discrepancy(): BelongsTo { return $this->belongsTo(Discrepancy::class, 'discrepancy_id'); }
}
