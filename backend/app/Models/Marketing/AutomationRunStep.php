<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRunStep extends Model
{
    public const STATUSES = ['pending', 'executed', 'blocked', 'failed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'output' => 'array',
            'scheduled_for' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(AutomationStep::class, 'step_id');
    }
}
