<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QaReview extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function job(): BelongsTo { return $this->belongsTo(StocktakeJob::class, 'job_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function findings(): HasMany { return $this->hasMany(QaFinding::class, 'qa_review_id'); }
    public function verificationTasks(): HasMany { return $this->hasMany(VerificationTask::class, 'qa_review_id'); }
    public function recountRequests(): HasMany { return $this->hasMany(RecountRequest::class, 'qa_review_id'); }
}
