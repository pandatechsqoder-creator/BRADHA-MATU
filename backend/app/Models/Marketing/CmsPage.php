<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsPage extends Model
{
    use HasPublicId, SoftDeletes;

    protected $table = 'cms_pages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_home' => 'boolean',
            'requires_auth' => 'boolean',
            'noindex' => 'boolean',
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CmsPageSection::class, 'page_id')->orderBy('sort_order');
    }

    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMetadata::class, 'seoable');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CmsPageVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CmsPageVersion::class, 'page_id')->orderByDesc('version_no');
    }

    public function effectiveSlug(): string
    {
        return $this->draft_slug ?: $this->slug;
    }

    public function isPubliclyVisible(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeIndexable(Builder $query): Builder
    {
        return $query->published()
            ->where('noindex', false)
            ->where('requires_auth', false)
            ->whereDoesntHave('seo', fn (Builder $seo) => $seo->where('robots_index', false));
    }
}
