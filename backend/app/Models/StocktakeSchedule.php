<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StocktakeSchedule extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'scope_config' => 'array',
            'recurrence_config' => 'array',
            'active' => 'boolean',
            'next_occurrence_at' => 'datetime',
            'last_occurrence_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function occurrences(): HasMany { return $this->hasMany(ScheduleOccurrence::class, 'schedule_id')->orderBy('scheduled_at'); }
}
