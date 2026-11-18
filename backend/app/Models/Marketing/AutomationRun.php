<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    use HasPublicId;

    public const STATUSES = ['queued', 'waiting', 'completed', 'blocked', 'failed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resume_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'lead_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class, 'run_id')->orderBy('position');
    }
}
