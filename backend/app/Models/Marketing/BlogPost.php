<?php

namespace App\Models\Marketing;

use App\Models\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlogPost extends Model
{
    use HasPublicId, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
            'noindex' => 'boolean',
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'reading_minutes' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function featuredAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'featured_asset_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag')->orderBy('name');
    }

    public function relatedPosts(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'blog_post_related', 'blog_post_id', 'related_post_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeIndexable(Builder $query): Builder
    {
        return $query->published()->where('noindex', false)->where('robots_index', true);
    }

    public function authorLabel(?string $fallback = null): string
    {
        return $this->author_name ?: ($this->author?->name ?: ($fallback ?? 'BRATHAMATU'));
    }

    public function effectiveExcerpt(int $limit = 180): string
    {
        $excerpt = trim((string) $this->excerpt);

        if ($excerpt === '') {
            $text = strip_tags((string) $this->body);
            $excerpt = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        }

        return mb_strlen($excerpt) > $limit ? mb_substr($excerpt, 0, $limit - 1).'…' : $excerpt;
    }

    public function readingMinutes(): int
    {
        if ($this->reading_minutes !== null && $this->reading_minutes > 0) {
            return $this->reading_minutes;
        }

        $words = str_word_count(strip_tags((string) $this->body));

        return max(1, (int) ceil($words / 220));
    }
}
