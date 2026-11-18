<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;

class MediaAsset extends Model
{
    use HasPublicId, SoftDeletes;

    protected $table = 'media_assets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'focal_point' => 'array',
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'version' => 'integer',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MediaAssetVersion::class, 'media_asset_id')->orderByDesc('version');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class, 'media_asset_id');
    }

    public function publicUrl(): string
    {
        return URL::to('/media/'.$this->uuid);
    }
}
