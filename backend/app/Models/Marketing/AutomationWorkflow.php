<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflow extends Model
{
    use HasPublicId;

    public const TRIGGERS = [
        'lead.created' => 'Lead created',
        'lead.assigned' => 'Lead assigned',
        'lead.status_changed' => 'Lead status changed',
    ];

    public const STATUSES = ['draft', 'active', 'paused'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'activated_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class, 'workflow_id')->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'workflow_id')->orderByDesc('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
