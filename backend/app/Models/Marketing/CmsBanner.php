<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsBanner extends Model
{
    use HasPublicId;

    protected $table = 'cms_banners';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $window) => $window->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $window) => $window->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
