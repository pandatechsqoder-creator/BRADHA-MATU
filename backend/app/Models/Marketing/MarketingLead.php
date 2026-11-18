<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketingLead extends Model
{
    use HasPublicId, SoftDeletes;

    public const TYPES = ['quote', 'pos_demo', 'contact', 'callback', 'partnership', 'other'];

    public const STATUSES = ['new', 'assigned', 'contacted', 'qualified', 'quote_sent', 'follow_up', 'won', 'lost', 'archived'];

    protected $table = 'marketing_leads';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'consent_marketing' => 'boolean',
            'consent_transactional' => 'boolean',
            'consent_at' => 'datetime',
            'metadata' => 'array',
            'last_activity_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class, 'lead_id')->orderByDesc('created_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class, 'lead_id')->orderByDesc('assigned_at');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
