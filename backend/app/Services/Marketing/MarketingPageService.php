<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CmsPage;
use App\Models\Marketing\CmsPageVersion;
use App\Models\Marketing\MarketingSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MarketingPageService
{
    public const STATUSES = ['draft', 'review', 'approved', 'scheduled', 'published', 'archived'];

    public function __construct(
        private readonly MediaUsageService $mediaUsages,
        private readonly SeoRedirectService $redirects,
        private readonly StructuredDataService $structuredData,
    ) {}

    /**
     * @param  array{search?: ?string, status?: ?string, type?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = []): array
    {
        $query = CmsPage::query()->with(['seo', 'currentVersion']);

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($where) => $where
                ->where('title', 'like', $search)
                ->orWhere('slug', 'like', $search)
                ->orWhere('draft_slug', 'like', $search));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $pages = $query->orderByDesc('is_home')->orderBy('title')->get();

        return [
            'pages' => $pages->map(fn (CmsPage $page) => $this->summary($page))->all(),
            'status_counts' => CmsPage::query()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->all(),
            'types' => CmsPage::query()->distinct()->orderBy('type')->pluck('type')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(CmsPage $page): array
    {
        return [
            'id' => $page->uuid,
            'slug' => $page->slug,
            'draft_slug' => $page->draft_slug,
            'effective_slug' => $page->effectiveSlug(),
            'title' => $page->title,
            'type' => $page->type,
            'status' => $page->status,
            'is_home' => $page->is_home,
            'noindex' => $page->noindex,
            'published_at' => $page->published_at?->toIso8601String(),
            'scheduled_at' => $page->scheduled_at?->toIso8601String(),
            'is_live' => $page->isPubliclyVisible(),
            'has_pending_changes' => $page->isPubliclyVisible() && $page->status !== 'published',
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(CmsPage $page): array
    {
        $page->load(['sections', 'seo', 'currentVersion', 'versions']);

        return array_merge($this->summary($page), [
            'template' => $page->template,
            'requires_auth' => $page->requires_auth,
            'locale' => $page->locale,
            'created_by' => $page->created_by,
            'reviewed_by' => $page->reviewed_by,
            'approved_by' => $page->approved_by,
            'published_by' => $page->published_by,
            'sections' => $page->sections->map(fn ($section) => [
                'id' => $section->id,
                'type' => $section->type,
                'sort_order' => $section->sort_order,
                'content' => $section->content ?? [],
                'settings' => $section->settings ?? [],
                'is_visible' => $section->is_visible,
            ])->values()->all(),
            'seo' => [
                'title' => $page->seo?->title,
                'description' => $page->seo?->description,
                'canonical_url' => $page->seo?->canonical_url,
                'robots_index' => $page->seo?->robots_index ?? true,
                'robots_follow' => $page->seo?->robots_follow ?? true,
                'og_title' => $page->seo?->og_title,
                'og_description' => $page->seo?->og_description,
                'twitter_card' => $page->seo?->twitter_card ?? 'summary_large_image',
                'priority' => (float) ($page->seo?->priority ?? 0.5),
                'change_frequency' => $page->seo?->change_frequency ?? 'monthly',
            ],
            'versions' => $page->versions->map(fn (CmsPageVersion $version) => [
                'id' => $version->uuid,
                'version_no' => $version->version_no,
                'status' => $version->status,
                'change_summary' => $version->change_summary,
                'created_by' => $version->created_by,
                'created_at' => $version->created_at?->toIso8601String(),
                'published_at' => $version->published_at?->toIso8601String(),
            ])->values()->all(),
            'current_version_id' => $page->currentVersion?->uuid,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): CmsPage
    {
        $slug = $this->normaliseSlug($data['slug']);
        $this->assertSlugAvailable($slug);

        $page = CmsPage::create([
            'slug' => $slug,
            'draft_slug' => null,
            'title' => $data['title'],
            'type' => $data['type'] ?? 'page',
            'template' => $data['template'] ?? 'public.page',
            'status' => 'draft',
            'locale' => $data['locale'] ?? 'en',
            'is_home' => false,
            'requires_auth' => (bool) ($data['requires_auth'] ?? false),
            'noindex' => (bool) ($data['noindex'] ?? false),
            'created_by' => $user->id,
        ]);

        $this->saveSeo($page, $data['seo'] ?? []);

        if (! empty($data['sections'])) {
            $this->replaceSections($page, $data['sections'], $user, recordVersion: false);
        }

        return $page->fresh(['sections', 'seo']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CmsPage $page, array $data, User $user): CmsPage
    {
        return DB::transaction(function () use ($page, $data, $user): CmsPage {
            $page = CmsPage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            $newSlug = isset($data['slug']) ? $this->normaliseSlug($data['slug']) : $page->effectiveSlug();

            if ($newSlug !== $page->effectiveSlug()) {
                $this->assertSlugAvailable($newSlug, $page);
            }

            $attributes = [
                'status' => 'draft',
                'reviewed_by' => null,
                'approved_by' => null,
                'scheduled_at' => null,
            ];

            foreach (['title', 'type', 'template', 'locale', 'requires_auth', 'noindex'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if ($newSlug !== $page->slug) {
                $attributes['draft_slug'] = $newSlug;
            } elseif ($page->draft_slug !== null) {
                $attributes['draft_slug'] = null;
            }

            $page->fill($attributes)->save();

            if (array_key_exists('seo', $data)) {
                $this->saveSeo($page, $data['seo'] ?? []);
            }

            if (array_key_exists('sections', $data)) {
                $this->replaceSections($page, $data['sections'] ?? [], $user, recordVersion: false);
            }

            $this->mediaUsages->syncPage($page);

            return $page->fresh(['sections', 'seo', 'currentVersion']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    public function replaceSections(CmsPage $page, array $sections, ?User $user = null, bool $recordVersion = false): void
    {
        DB::transaction(function () use ($page, $sections, $recordVersion): void {
            $page->sections()->delete();

            foreach (array_values($sections) as $index => $section) {
                $page->sections()->create([
                    'type' => $section['type'],
                    'sort_order' => $index,
                    'content' => $section['content'] ?? [],
                    'settings' => $section['settings'] ?? [],
                    'is_visible' => (bool) ($section['is_visible'] ?? true),
                ]);
            }

            if ($recordVersion) {
                $page->fill(['status' => 'draft', 'reviewed_by' => null, 'approved_by' => null, 'scheduled_at' => null])->save();
            }

            $this->mediaUsages->syncPage($page->fresh());
        });
    }

    public function submitReview(CmsPage $page, User $user, ?string $summary = null): CmsPageVersion
    {
        return $this->transition($page, ['draft'], function (CmsPage $page) use ($user, $summary): void {
            if ($page->sections()->where('is_visible', true)->doesntExist()) {
                throw ValidationException::withMessages([
                    'workflow' => 'Add at least one visible section before submitting for review.',
                ]);
            }

            $page->fill([
                'status' => 'review',
                'reviewed_by' => null,
                'approved_by' => null,
                'scheduled_at' => null,
            ])->save();

            $this->createVersion($page, 'review', $user, $summary ?: 'Submitted for review');
        });
    }

    public function approve(CmsPage $page, User $user, ?string $summary = null): CmsPageVersion
    {
        return $this->transition($page, ['review'], function (CmsPage $page) use ($user, $summary): void {
            if ($this->separationOfDuties() && $page->created_by !== null && $page->created_by === $user->id) {
                throw ValidationException::withMessages([
                    'workflow' => 'A page cannot be approved by the person who created it while separation of duties is enabled.',
                ]);
            }

            $page->fill([
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'approved_by' => $user->id,
            ])->save();

            $this->createVersion($page, 'approved', $user, $summary ?: 'Approved');
        });
    }

    public function reject(CmsPage $page, User $user, string $reason): CmsPageVersion
    {
        return $this->transition($page, ['review'], function (CmsPage $page) use ($user, $reason): void {
            $page->fill([
                'status' => 'draft',
                'reviewed_by' => $user->id,
                'approved_by' => null,
            ])->save();

            $this->createVersion($page, 'draft', $user, 'Rejected: '.$reason);
        });
    }

    public function schedule(CmsPage $page, User $user, \DateTimeInterface $scheduledAt): CmsPageVersion
    {
        if ($scheduledAt <= now()) {
            throw ValidationException::withMessages(['scheduled_at' => 'Choose a future date and time.']);
        }

        return $this->transition($page, ['approved'], function (CmsPage $page) use ($user, $scheduledAt): void {
            $page->fill([
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt,
            ])->save();

            $this->createVersion($page, 'scheduled', $user, 'Scheduled for '.$scheduledAt->format('Y-m-d H:i'));
        });
    }

    public function publish(CmsPage $page, ?User $user, ?string $summary = null): CmsPageVersion
    {
        return DB::transaction(function () use ($page, $user, $summary): CmsPageVersion {
            $page = CmsPage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            if (! in_array($page->status, ['approved', 'scheduled'], true)) {
                throw ValidationException::withMessages([
                    'workflow' => 'Approve the page before publishing it.',
                ]);
            }

            $structuredData = $this->structuredData->validateSnapshot($page, $this->snapshot($page))['validation'];

            if ($structuredData['status'] !== 'valid') {
                throw ValidationException::withMessages([
                    'structured_data' => $this->structuredData->failureMessage($structuredData),
                ]);
            }

            if ($page->draft_slug !== null && $page->draft_slug !== $page->slug) {
                $this->assertSlugAvailable($page->draft_slug, $page);
                $oldSlug = $page->slug;
                $page->slug = $page->draft_slug;
                $page->draft_slug = null;

                if ($page->published_at !== null) {
                    $this->redirects->recordSlugChange($page, $oldSlug, $user);
                }
            }

            $page->fill([
                'status' => 'published',
                'published_at' => now(),
                'scheduled_at' => null,
                'published_by' => $user?->id,
            ])->save();

            $version = $this->createVersion($page, 'published', $user, $summary ?: 'Published', now());
            $page->forceFill(['current_version_id' => $version->id])->save();

            $this->mediaUsages->syncPage($page->fresh());

            return $version;
        });
    }

    public function unpublish(CmsPage $page, User $user): CmsPage
    {
        if ($page->published_at === null) {
            throw ValidationException::withMessages(['workflow' => 'This page is not published.']);
        }

        $page->fill([
            'status' => 'draft',
            'published_at' => null,
            'scheduled_at' => null,
        ])->save();

        return $page->fresh();
    }

    public function restoreVersion(CmsPage $page, CmsPageVersion $version, User $user): CmsPage
    {
        return DB::transaction(function () use ($page, $version, $user): CmsPage {
            if ((int) $version->page_id !== (int) $page->id) {
                throw ValidationException::withMessages(['version' => 'That version does not belong to this page.']);
            }

            $snapshot = $version->snapshot;
            $page->fill([
                'title' => $snapshot['page']['title'] ?? $page->title,
                'type' => $snapshot['page']['type'] ?? $page->type,
                'template' => $snapshot['page']['template'] ?? $page->template,
                'is_home' => (bool) ($snapshot['page']['is_home'] ?? false),
                'requires_auth' => (bool) ($snapshot['page']['requires_auth'] ?? false),
                'noindex' => (bool) ($snapshot['page']['noindex'] ?? false),
                'locale' => $snapshot['page']['locale'] ?? $page->locale,
                'draft_slug' => ($snapshot['page']['slug'] ?? $page->slug) !== $page->slug
                    ? ($snapshot['page']['slug'] ?? null)
                    : null,
                'status' => 'draft',
                'reviewed_by' => null,
                'approved_by' => null,
                'scheduled_at' => null,
            ])->save();

            $this->replaceSections($page, $snapshot['sections'] ?? [], $user, recordVersion: false);
            $this->saveSeo($page, $snapshot['seo'] ?? []);
            $this->createVersion($page, 'draft', $user, 'Restored version '.$version->version_no);

            return $page->fresh(['sections', 'seo', 'currentVersion']);
        });
    }

    public function delete(CmsPage $page, User $user): void
    {
        if ($page->isPubliclyVisible()) {
            throw ValidationException::withMessages(['workflow' => 'Unpublish the page before deleting it.']);
        }

        $page->delete();
    }

    public function previewVersion(CmsPage $page, ?CmsPageVersion $version = null): CmsPageVersion
    {
        return $version
            ?? $page->versions()->first()
            ?? $this->createVersion($page, $page->status, null, 'Preview snapshot');
    }

    public function previewUrl(CmsPage $page, ?CmsPageVersion $version = null): string
    {
        $version = $this->previewVersion($page, $version);

        return URL::temporarySignedRoute(
            'marketing.preview',
            now()->addMinutes(30),
            ['page' => $page->uuid, 'version' => $version->uuid],
        );
    }

    public function ensurePublishedVersion(CmsPage $page): void
    {
        if ($page->published_at === null || $page->current_version_id !== null) {
            return;
        }

        $version = $this->createVersion($page, 'published', null, 'Initial published version', $page->published_at);
        $page->forceFill(['current_version_id' => $version->id])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(CmsPage $page): array
    {
        $page->loadMissing(['sections', 'seo']);

        return [
            'page' => [
                'slug' => $page->effectiveSlug(),
                'title' => $page->title,
                'type' => $page->type,
                'template' => $page->template,
                'is_home' => $page->is_home,
                'requires_auth' => $page->requires_auth,
                'noindex' => $page->noindex,
                'locale' => $page->locale,
            ],
            'sections' => $page->sections->map(fn ($section) => [
                'type' => $section->type,
                'sort_order' => $section->sort_order,
                'content' => $section->content ?? [],
                'settings' => $section->settings ?? [],
                'is_visible' => $section->is_visible,
            ])->values()->all(),
            'seo' => [
                'title' => $page->seo?->title,
                'description' => $page->seo?->description,
                'canonical_url' => $page->seo?->canonical_url,
                'robots_index' => $page->seo?->robots_index ?? true,
                'robots_follow' => $page->seo?->robots_follow ?? true,
                'og_title' => $page->seo?->og_title,
                'og_description' => $page->seo?->og_description,
                'twitter_card' => $page->seo?->twitter_card ?? 'summary_large_image',
                'priority' => (float) ($page->seo?->priority ?? 0.5),
                'change_frequency' => $page->seo?->change_frequency ?? 'monthly',
            ],
        ];
    }

    private function createVersion(CmsPage $page, string $status, ?User $user, ?string $summary, ?\DateTimeInterface $publishedAt = null): CmsPageVersion
    {
        $page = $page->fresh(['sections', 'seo']);
        $versionNo = (int) CmsPageVersion::query()->where('page_id', $page->id)->max('version_no') + 1;

        return CmsPageVersion::create([
            'page_id' => $page->id,
            'version_no' => $versionNo,
            'status' => $status,
            'snapshot' => $this->snapshot($page),
            'change_summary' => $summary,
            'created_by' => $user?->id,
            'reviewed_by' => $status === 'approved' ? $user?->id : null,
            'approved_by' => $status === 'approved' ? $user?->id : null,
            'published_at' => $publishedAt,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $allowedFrom
     */
    private function transition(CmsPage $page, array $allowedFrom, callable $callback): CmsPageVersion
    {
        return DB::transaction(function () use ($page, $allowedFrom, $callback): CmsPageVersion {
            $page = CmsPage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            if (! in_array($page->status, $allowedFrom, true)) {
                throw ValidationException::withMessages([
                    'workflow' => 'This action is not available while the page is '.$page->status.'.',
                ]);
            }

            $callback($page);

            return $page->versions()->first();
        });
    }

    private function separationOfDuties(): bool
    {
        $stored = MarketingSetting::query()->where('key', 'marketing.separation_of_duties')->value('value');

        if ($stored === null) {
            return true;
        }

        return (bool) json_decode($stored, true);
    }

    /**
     * @param  array<string, mixed>  $seo
     */
    private function saveSeo(CmsPage $page, array $seo): void
    {
        $page->seo()->updateOrCreate([], [
            'title' => $seo['title'] ?? null,
            'description' => $seo['description'] ?? null,
            'canonical_url' => $seo['canonical_url'] ?? null,
            'robots_index' => (bool) ($seo['robots_index'] ?? true),
            'robots_follow' => (bool) ($seo['robots_follow'] ?? true),
            'og_title' => $seo['og_title'] ?? null,
            'og_description' => $seo['og_description'] ?? null,
            'twitter_card' => $seo['twitter_card'] ?? 'summary_large_image',
            'priority' => $seo['priority'] ?? 0.5,
            'change_frequency' => $seo['change_frequency'] ?? 'monthly',
            'updated_by' => auth()->id(),
        ]);
    }

    private function normaliseSlug(string $slug): string
    {
        $slug = trim(Str::lower($slug), '/');

        if ($slug === '') {
            throw ValidationException::withMessages(['slug' => 'A URL slug is required.']);
        }

        return $slug;
    }

    private function assertSlugAvailable(string $slug, ?CmsPage $except = null): void
    {
        $query = CmsPage::withTrashed()
            ->where(fn ($where) => $where->where('slug', $slug)->orWhere('draft_slug', $slug));

        if ($except !== null) {
            $query->whereKeyNot($except->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['slug' => 'That URL slug is already in use.']);
        }
    }
}
