<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaAdAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'spend_cap' => 'float',
            'synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(OAuthConnection::class, 'connection_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(MetaCampaignRecord::class, 'ad_account_id');
    }
}
