<?php

namespace App\Models\Marketing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaPage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'page_token' => 'encrypted',
            'insights' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(OAuthConnection::class, 'connection_id');
    }

    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(MetaBusinessAccount::class, 'meta_business_account_id');
    }

    public function instagramAccounts(): HasMany
    {
        return $this->hasMany(MetaInstagramAccount::class, 'meta_page_id');
    }
}
