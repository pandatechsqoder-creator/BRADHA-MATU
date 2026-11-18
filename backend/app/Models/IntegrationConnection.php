<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationConnection extends Model
{
    use BelongsToTenant, HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'webhook_secret' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(IntegrationMapping::class, 'connection_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(IntegrationSyncRun::class, 'connection_id');
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(IntegrationConflict::class, 'connection_id');
    }
}
