<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleOccurrence extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function schedule(): BelongsTo { return $this->belongsTo(StocktakeSchedule::class, 'schedule_id'); }
    public function job(): BelongsTo { return $this->belongsTo(StocktakeJob::class, 'job_id'); }
}
