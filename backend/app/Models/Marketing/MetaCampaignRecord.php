<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaCampaignRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'budget_amount' => 'float',
            'spend_amount' => 'float',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'results' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function adAccount(): BelongsTo
    {
        return $this->belongsTo(MetaAdAccount::class, 'ad_account_id');
    }
}
