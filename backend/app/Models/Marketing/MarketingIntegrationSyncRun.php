<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingIntegrationSyncRun extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counts' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(OAuthConnection::class, 'connection_id');
    }
}
