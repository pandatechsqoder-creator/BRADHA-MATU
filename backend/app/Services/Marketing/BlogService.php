<?php

namespace App\Services\Marketing;

use App\Models\Marketing\BlogCategory;
use App\Models\Marketing\BlogPost;
use App\Models\Marketing\BlogTag;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BlogService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MarketingSettings $settings,
        private readonly StructuredDataService $structuredData,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function adminList(array $filters): array
    {
        $query = BlogPost::query()
            ->with(['category', 'tags', 'author', 'featuredAsset'])
            ->orderByDesc('updated_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $category = $filters['category'];
            $query->whereHas('category', fn (Builder $where) => $where->where('uuid', $category));
        }

        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
            $query->where(fn (Builder $where) => $where
                ->where('title', 'like', $term)
                ->orWhere('excerpt', 'like', $term)
                ->orWhere('slug', 'like', $term));
        }

        return [
            'posts' => $query->get()->map(fn (BlogPost $post) => $this->present($post))->all(),
            'status_counts' => BlogPost::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all(),
            'categories' => BlogCategory::query()->orderBy('sort_order')->orderBy('name')
                ->get()
                ->map(fn (BlogCategory $category) => [
                    'id' => $category->uuid,
                    'slug' => $category->slug,
                    'name' => $category->name,
                ])->all(),
            'tags' => BlogTag::query()->orderBy('name')->get()
                ->map(fn (BlogTag $tag) => ['id' => $tag->uuid, 'slug' => $tag->slug, 'name' => $tag->name])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function present(BlogPost $post): array
    {
        return [
            'id' => $post->uuid,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'format' => $post->format,
            'status' => $post->status,
            'author_name' => $post->authorLabel(),
            'author_id' => $post->author?->uuid,
            'category' => $post->category ? [
                'id' => $post->category->uuid,
                'slug' => $post->category->slug,
                'name' => $post->category->name,
            ] : null,
            'tags' => $post->tags->map(fn (BlogTag $tag) => [
                'id' => $tag->uuid,
                'slug' => $tag->slug,
                'name' => $tag->name,
            ])->all(),
            'related_ids' => $post->relatedPosts->pluck('uuid')->all(),
            'reading_minutes' => $post->readingMinutes(),
            'seo_title' => $post->seo_title,
            'seo_description' => $post->seo_description,
            'canonical_url' => $post->canonical_url,
            'og_title' => $post->og_title,
            'og_description' => $post->og_description,
            'robots_index' => $post->robots_index,
            'robots_follow' => $post->robots_follow,
            'noindex' => $post->noindex,
            'published_at' => $post->published_at?->toIso8601String(),
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'is_live' => $post->status === 'published'
                && $post->published_at !== null
                && $post->published_at->isPast(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function view(BlogPost $post): array
    {
        $post->loadMissing(['category', 'tags', 'author']);

        $description = trim((string) $post->seo_description) ?: $post->effectiveExcerpt(220);

        return [
            'id' => $post->uuid,
            'slug' => $post->slug,
            'title' => $post->title,
            'description' => $description,
            'seo_title' => trim((string) $post->seo_title) ?: $post->title,
            'canonical' => $post->canonical_url ?: PublicUrls::path('resources/'.$post->slug),
            'path' => '/resources/'.$post->slug,
            'excerpt' => $post->effectiveExcerpt(),
            'author' => $post->authorLabel(),
            'category' => $post->category ? [
                'id' => $post->category->uuid,
                'slug' => $post->category->slug,
                'name' => $post->category->name,
            ] : null,
            'tags' => $post->tags->map(fn (BlogTag $tag) => [
                'slug' => $tag->slug,
                'name' => $tag->name,
            ])->all(),
            'image_url' => $post->featuredAsset?->publicUrl(),
            'published_at' => ($post->published_at ?? $post->updated_at)?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'reading_minutes' => $post->readingMinutes(),
            'noindex' => (bool) $post->noindex || ! $post->robots_index,
            'robots_follow' => (bool) $post->robots_follow,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(BlogPost $post): array
    {
        $post->loadMissing(['category', 'tags', 'author', 'featuredAsset']);

        return [
            'id' => $post->uuid,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->effectiveExcerpt(),
            'url' => PublicUrls::path('resources/'.$post->slug),
            'author' => $post->authorLabel(),
            'category' => $post->category ? [
                'slug' => $post->category->slug,
                'name' => $post->category->name,
            ] : null,
            'tags' => $post->tags->map(fn (BlogTag $tag) => [
                'slug' => $tag->slug,
                'name' => $tag->name,
            ])->all(),
            'image_url' => $post->featuredAsset?->publicUrl(),
            'published_at' => $post->published_at?->toIso8601String(),
            'reading_minutes' => $post->readingMinutes(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function related(BlogPost $post, int $limit = 3): array
    {
        $related = $post->relatedPosts()
            ->published()
            ->with(['category', 'tags', 'author', 'featuredAsset'])
            ->limit($limit)
            ->get();

        if ($related->count() < $limit && $post->category_id !== null) {
            $fallback = BlogPost::query()
                ->published()
                ->whereKeyNot($post->id)
                ->where('category_id', $post->category_id)
                ->with(['category', 'tags', 'author', 'featuredAsset'])
                ->orderByDesc('published_at')
                ->limit($limit - $related->count())
                ->get();

            $related = $related->merge($fallback)->unique('id')->take($limit);
        }

        return $related->map(fn (BlogPost $item) => $this->summary($item))->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): BlogPost
    {
        $post = new BlogPost;
        $this->fill($post, $data);
        $post->status = 'draft';
        $post->created_by = $user->id;
        $post->author_id = $post->author_id ?? $user->id;
        $post->save();

        $this->syncRelations($post, $data);
        $this->audit->log('marketing.post.created', $post, null, null, ['slug' => $post->slug]);

        return $this->load($post);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BlogPost $post, array $data, User $user): BlogPost
    {
        $before = ['status' => $post->status, 'slug' => $post->slug];
        $this->fill($post, $data);
        $post->save();

        $this->syncRelations($post, $data);
        $this->audit->log('marketing.post.updated', $post, null, $before, [
            'status' => $post->status,
            'slug' => $post->slug,
        ]);

        return $this->load($post);
    }

    public function delete(BlogPost $post): void
    {
        $before = ['status' => $post->status, 'slug' => $post->slug];
        $post->delete();
        $this->audit->log('marketing.post.deleted', $post, null, $before, null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transition(BlogPost $post, string $action, User $user, array $payload = []): BlogPost
    {
        $before = ['status' => $post->status];

        match ($action) {
            'submit-review' => $this->submitReview($post),
            'approve' => $this->approve($post, $user),
            'reject' => $this->reject($post, (string) ($payload['reason'] ?? '')),
            'schedule' => $this->schedule($post, (string) ($payload['scheduled_at'] ?? '')),
            'publish' => $this->publish($post, $user, $payload['change_summary'] ?? null),
            'unpublish' => $this->unpublish($post),
            'archive' => $this->archive($post),
            default => throw ValidationException::withMessages(['action' => 'Unknown workflow action.']),
        };

        $this->audit->log('marketing.post.'.$action, $post, null, $before, ['status' => $post->status]);

        return $this->load($post);
    }

    public function publishScheduled(BlogPost $post): BlogPost
    {
        $this->publish($post, null, 'Scheduled publish');

        return $this->load($post);
    }

    private function submitReview(BlogPost $post): void
    {
        $this->ensureEditable($post, ['draft', 'archived']);
        $this->ensureComplete($post);

        $post->status = 'review';
        $post->save();
    }

    private function approve(BlogPost $post, User $user): void
    {
        if ($post->status !== 'review') {
            throw ValidationException::withMessages(['status' => 'Only posts in review can be approved.']);
        }

        if ($this->separationOfDuties() && (int) $post->created_by === (int) $user->id) {
            throw ValidationException::withMessages([
                'workflow' => 'A post cannot be approved by the person who created it while separation of duties is enabled.',
            ]);
        }

        $post->status = 'approved';
        $post->reviewed_by = $user->id;
        $post->approved_by = $user->id;
        $post->save();
    }

    private function reject(BlogPost $post, string $reason): void
    {
        if ($post->status !== 'review') {
            throw ValidationException::withMessages(['status' => 'Only posts in review can be rejected.']);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }

        $post->status = 'draft';
        $post->save();
    }

    private function schedule(BlogPost $post, string $scheduledAt): void
    {
        if ($post->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only approved posts can be scheduled.']);
        }

        if (trim($scheduledAt) === '') {
            throw ValidationException::withMessages(['scheduled_at' => 'A publication time is required.']);
        }

        $post->status = 'scheduled';
        $post->scheduled_at = $scheduledAt;
        $post->save();
    }

    private function publish(BlogPost $post, ?User $user, ?string $summary): void
    {
        if (! in_array($post->status, ['approved', 'scheduled', 'published'], true)) {
            throw ValidationException::withMessages(['status' => 'Only approved, scheduled or published posts can be published.']);
        }

        $this->ensureComplete($post);

        $view = $this->view($post);
        $graph = $this->structuredData->buildArticle($view);
        $validation = $this->structuredData->validateArticle($view, $graph);

        if ($validation['status'] !== 'valid') {
            throw ValidationException::withMessages([
                'structured_data' => $this->structuredData->failureMessage($validation),
            ]);
        }

        $post->status = 'published';
        $post->published_at = $post->published_at ?? now();
        $post->scheduled_at = null;
        $post->save();
    }

    private function unpublish(BlogPost $post): void
    {
        if ($post->status !== 'published') {
            throw ValidationException::withMessages(['status' => 'Only published posts can be unpublished.']);
        }

        $post->status = 'draft';
        $post->published_at = null;
        $post->save();
    }

    private function archive(BlogPost $post): void
    {
        if (! in_array($post->status, ['draft', 'published'], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft or published posts can be archived.']);
        }

        $post->status = 'archived';
        $post->published_at = null;
        $post->save();
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function ensureEditable(BlogPost $post, array $allowed): void
    {
        if (! in_array($post->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'This workflow step is not available from the current status.']);
        }
    }

    private function ensureComplete(BlogPost $post): void
    {
        $errors = [];

        if (trim((string) $post->title) === '') {
            $errors['title'] = 'A title is required before publishing.';
        }
        if (trim((string) $post->slug) === '') {
            $errors['slug'] = 'A slug is required before publishing.';
        }
        if (trim((string) $post->body) === '') {
            $errors['body'] = 'Article body content is required before publishing.';
        }
        if (trim((string) $post->excerpt) === '' && mb_strlen(strip_tags((string) $post->body)) < 40) {
            $errors['excerpt'] = 'An excerpt is required for short articles.';
        }
        if ($post->category_id === null) {
            $errors['category_id'] = 'A primary category is required before publishing.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function separationOfDuties(): bool
    {
        return (bool) $this->settings->get('marketing.separation_of_duties', true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(BlogPost $post, array $data): void
    {
        foreach (['title', 'excerpt', 'body', 'format', 'author_name', 'seo_title', 'seo_description',
            'canonical_url', 'og_title', 'og_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $post->{$field} = $data[$field];
            }
        }

        if (array_key_exists('slug', $data)) {
            $post->slug = $this->normaliseSlug((string) $data['slug'], $post->id);
        }

        if (array_key_exists('reading_minutes', $data)) {
            $post->reading_minutes = $data['reading_minutes'] !== null ? (int) $data['reading_minutes'] : null;
        }

        foreach (['robots_index', 'robots_follow', 'noindex'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $post->{$flag} = (bool) $data[$flag];
            }
        }

        if (array_key_exists('category_id', $data)) {
            $post->category_id = $data['category_id'] === null
                ? null
                : BlogCategory::query()->where('uuid', $data['category_id'])->value('id');
        }

        if (array_key_exists('author_id', $data)) {
            $post->author_id = $data['author_id'] === null
                ? null
                : User::query()->where('uuid', $data['author_id'])->value('id');
        }
    }

    private function normaliseSlug(string $slug, ?int $ignoreId): string
    {
        $slug = trim(Str::slug($slug), '-');

        if ($slug === '') {
            throw ValidationException::withMessages(['slug' => 'A valid slug is required.']);
        }

        $exists = BlogPost::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $where) => $where->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['slug' => 'This slug is already used by another article.']);
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncRelations(BlogPost $post, array $data): void
    {
        if (array_key_exists('tags', $data)) {
            $ids = collect(Arr::wrap($data['tags'] ?? []))
                ->filter(fn ($tag) => is_string($tag) && trim($tag) !== '')
                ->map(function (string $tag): int {
                    $slug = Str::slug($tag);

                    return BlogTag::query()->firstOrCreate(
                        ['slug' => $slug],
                        ['name' => Str::title(str_replace('-', ' ', $slug))],
                    )->id;
                })
                ->unique()
                ->values();

            $post->tags()->sync($ids->all());
        }

        if (array_key_exists('related_ids', $data)) {
            $ids = collect(Arr::wrap($data['related_ids'] ?? []))
                ->map(fn ($uuid) => BlogPost::query()->where('uuid', $uuid)->whereKeyNot($post->id)->value('id'))
                ->filter()
                ->unique()
                ->values();

            $post->relatedPosts()->sync($ids->mapWithKeys(fn (int $id, int $index) => [$id => ['sort_order' => $index]])->all());
        }
    }

    private function load(BlogPost $post): BlogPost
    {
        return $post->fresh(['category', 'tags', 'author', 'relatedPosts']);
    }
}
