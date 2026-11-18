<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OAuthConnection extends Model
{
    use HasPublicId;

    protected $table = 'oauth_connections';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'scopes' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(MarketingIntegrationSyncRun::class, 'connection_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MarketingIntegrationLog::class, 'connection_id');
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && is_array($this->credentials) && ($this->credentials['access_token'] ?? null);
    }
}
