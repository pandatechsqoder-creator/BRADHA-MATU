<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StocktakingDispute extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $casts = ['resolved_at' => 'datetime', 'closed_at' => 'datetime'];

    public function job(): BelongsTo { return $this->belongsTo(StocktakeJob::class, 'job_id'); }
    public function raisedBy(): BelongsTo { return $this->belongsTo(User::class, 'raised_by'); }
    public function messages(): HasMany { return $this->hasMany(DisputeMessage::class, 'dispute_id')->orderBy('created_at'); }
    public function evidence(): HasMany { return $this->hasMany(DisputeEvidence::class, 'dispute_id'); }
    public function decisions(): HasMany { return $this->hasMany(DisputeDecision::class, 'dispute_id')->orderBy('created_at'); }
}
