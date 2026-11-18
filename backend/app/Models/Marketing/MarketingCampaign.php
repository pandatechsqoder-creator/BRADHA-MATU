<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'utm' => 'array',
            'results' => 'array',
            'budget_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'landing_page_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(CampaignAsset::class, 'campaign_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(CampaignApproval::class, 'campaign_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CampaignRevision::class, 'campaign_id')->orderByDesc('revision_no');
    }
}
