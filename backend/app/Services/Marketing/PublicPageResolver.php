<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CmsFaq;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\CmsPageSection;
use App\Models\Marketing\CmsPageVersion;
use App\Models\Marketing\CmsTestimonial;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class PublicPageResolver
{
    /**
     * Section types with a matching Blade partial in resources/views/public/sections.
     */
    public const SUPPORTED_SECTIONS = [
        'hero',
        'rich_text',
        'service_grid',
        'industries',
        'pos_features',
        'process',
        'faq',
        'faq_library',
        'testimonials',
        'cta',
        'contact',
        'form',
        'legal',
    ];

    public function __construct(private readonly MarketingSettings $settings) {}

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $path): ?array
    {
        $path = trim($path, '/');

        try {
            $query = CmsPage::query()
                ->published()
                ->where('requires_auth', false)
                ->with([
                    'sections' => fn ($query) => $query->where('is_visible', true),
                    'seo',
                    'currentVersion',
                ]);

            $page = $path === ''
                ? (clone $query)->where('is_home', true)->first() ?? (clone $query)->where('slug', 'home')->first()
                : $query->where('slug', $path)->first();
        } catch (QueryException) {
            return null;
        }

        if ($page === null) {
            return null;
        }

        return $this->toView($page, $path);
    }

    /**
     * Render an unpublished working copy through a signed preview URL.
     *
     * @return array<string, mixed>
     */
    public function preview(CmsPage $page, CmsPageVersion $version): array
    {
        $snapshot = $version->snapshot;
        $path = trim((string) ($snapshot['page']['slug'] ?? $page->effectiveSlug()), '/');

        return $this->fromSnapshot($page, $path, $snapshot, preview: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function toView(CmsPage $page, string $path): array
    {
        $snapshot = $page->currentVersion?->snapshot;

        if (is_array($snapshot)) {
            return $this->fromSnapshot($page, $path, $snapshot);
        }

        return $this->fromLivePage($page, $path);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function fromSnapshot(CmsPage $page, string $path, array $snapshot, bool $preview = false): array
    {
        $brand = $this->settings->brand();
        $data = $snapshot['page'] ?? [];
        $seoData = $snapshot['seo'] ?? [];
        $title = $seoData['title'] ?? ($data['title'] ?? $page->title);
        $suffix = $this->settings->titleSuffix();

        if (! ($data['is_home'] ?? $page->is_home) && $suffix !== '' && ! Str::contains($title, $suffix)) {
            $title .= $suffix;
        }

        $description = ($seoData['description'] ?? null)
            ?: $this->settings->defaultDescription()
            ?: $brand['short_description'];

        $indexable = ! $preview
            && (bool) config('marketing.indexable')
            && ! ($data['noindex'] ?? $page->noindex)
            && ! ($data['requires_auth'] ?? $page->requires_auth)
            && ($seoData['robots_index'] ?? true)
            && $page->isPubliclyVisible();

        $follow = $preview ? false : ($seoData['robots_follow'] ?? true);
        $sections = collect($snapshot['sections'] ?? [])
            ->filter(fn ($section) => ($section['is_visible'] ?? true)
                && in_array($section['type'] ?? null, self::SUPPORTED_SECTIONS, true))
            ->map(fn (array $section) => [
                'type' => $section['type'],
                'content' => $section['content'] ?? [],
                'settings' => $section['settings'] ?? [],
            ])
            ->values()
            ->all();

        return [
            'id' => $page->id,
            'slug' => $data['slug'] ?? $page->slug,
            'path' => $path === '' ? '/' : '/'.$path,
            'title' => $data['title'] ?? $page->title,
            'type' => $data['type'] ?? $page->type,
            'template' => 'public.page',
            'is_home' => (bool) ($data['is_home'] ?? $page->is_home),
            'sections' => $this->hydrateLibrarySections($sections, $page),
            'breadcrumbs' => $this->breadcrumbs($path, $page, $data['title'] ?? $page->title),
            'brand' => $brand,
            'contact' => $this->settings->contact(),
            'social' => $this->settings->social(),
            'preview' => $preview,
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonical' => $preview ? null : ($seoData['canonical_url'] ?? $this->canonicalUrl($path)),
                'robots' => $preview ? 'noindex, nofollow' : (($indexable ? 'index' : 'noindex').', '.($follow ? 'follow' : 'nofollow')),
                'og_title' => $seoData['og_title'] ?? $title,
                'og_description' => $seoData['og_description'] ?? $description,
                'og_type' => 'website',
                'og_site_name' => $brand['name'],
                'twitter_card' => $seoData['twitter_card'] ?? 'summary_large_image',
            ],
            'meta' => [
                'priority' => (float) ($seoData['priority'] ?? 0.5),
                'change_frequency' => $seoData['change_frequency'] ?? 'weekly',
                'last_modified' => $page->currentVersion?->created_at ?? $page->updated_at,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromLivePage(CmsPage $page, string $path): array
    {
        $brand = $this->settings->brand();
        $meta = $page->seo;

        $title = $meta?->title ?: $page->title;
        $suffix = $this->settings->titleSuffix();

        if (! $page->is_home && $suffix !== '' && ! Str::contains($title, $suffix)) {
            $title .= $suffix;
        }

        $description = $meta?->description
            ?: $this->settings->defaultDescription()
            ?: $brand['short_description'];

        $indexable = (bool) config('marketing.indexable')
            && ! $page->noindex
            && ! $page->requires_auth
            && ($meta?->robots_index ?? true)
            && $page->isPubliclyVisible();

        $follow = $meta?->robots_follow ?? true;
        $sections = $this->sections($page);

        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'path' => $path === '' ? '/' : '/'.$path,
            'title' => $page->title,
            'type' => $page->type,
            'template' => 'public.page',
            'is_home' => $page->is_home,
            'sections' => $this->hydrateLibrarySections($sections, $page),
            'breadcrumbs' => $this->breadcrumbs($path, $page),
            'brand' => $brand,
            'contact' => $this->settings->contact(),
            'social' => $this->settings->social(),
            'preview' => false,
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonical' => $meta?->canonical_url ?: $this->canonicalUrl($path),
                'robots' => ($indexable ? 'index' : 'noindex').', '.($follow ? 'follow' : 'nofollow'),
                'og_title' => $meta?->og_title ?: $title,
                'og_description' => $meta?->og_description ?: $description,
                'og_type' => 'website',
                'og_site_name' => $brand['name'],
                'twitter_card' => $meta?->twitter_card ?: 'summary_large_image',
            ],
            'meta' => [
                'priority' => $meta?->priority ?? 0.5,
                'change_frequency' => $meta?->change_frequency ?? 'weekly',
                'last_modified' => $page->updated_at,
            ],
        ];
    }

    /**
     * @param  array<int, array{type: string, content: array<string, mixed>, settings: array<string, mixed>}>  $sections
     * @return array<int, array{type: string, content: array<string, mixed>, settings: array<string, mixed>}>
     */
    private function hydrateLibrarySections(array $sections, CmsPage $page): array
    {
        return array_map(function (array $section) use ($page): array {
            if ($section['type'] === 'faq_library') {
                $section['content']['items'] = $this->faqs($page);
            }

            if ($section['type'] === 'testimonials') {
                $section['content']['items'] = $this->testimonials($page);
            }

            return $section;
        }, $sections);
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    private function faqs(CmsPage $page): array
    {
        try {
            return CmsFaq::query()
                ->where('is_visible', true)
                ->where(fn ($where) => $where->whereNull('page_id')->orWhere('page_id', $page->id))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn ($faq) => ['question' => $faq->question, 'answer' => $faq->answer])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function testimonials(CmsPage $page): array
    {
        try {
            return CmsTestimonial::query()
                ->with('asset')
                ->where('is_approved', true)
                ->where(fn ($where) => $where->whereNull('page_id')->orWhere('page_id', $page->id))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (CmsTestimonial $testimonial) => [
                    'quote' => $testimonial->quote,
                    'author_name' => $testimonial->author_name,
                    'author_role' => $testimonial->author_role,
                    'business_name' => $testimonial->business_name,
                    'image_url' => $testimonial->asset?->publicUrl(),
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array<int, array{type: string, content: array<string, mixed>, settings: array<string, mixed>}>
     */
    private function sections(CmsPage $page): array
    {
        return $page->sections
            ->filter(fn (CmsPageSection $section) => in_array($section->type, self::SUPPORTED_SECTIONS, true))
            ->map(fn (CmsPageSection $section) => [
                'type' => $section->type,
                'content' => $section->content ?? [],
                'settings' => $section->settings ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, url: ?string}>
     */
    private function breadcrumbs(string $path, CmsPage $page, ?string $title = null): array
    {
        $breadcrumbs = [['label' => 'Home', 'url' => url('/')]];

        if ($path === '') {
            return $breadcrumbs;
        }

        $segments = explode('/', $path);
        $segments = array_slice($segments, 0, -1);

        $slug = null;
        foreach ($segments as $segment) {
            $slug = $slug === null ? $segment : $slug.'/'.$segment;
            $ancestor = CmsPage::query()->published()->where('slug', $slug)->first();

            if ($ancestor !== null) {
                $breadcrumbs[] = ['label' => $ancestor->title, 'url' => url('/'.$ancestor->slug)];
            }
        }

        $breadcrumbs[] = ['label' => $title ?? $page->title, 'url' => null];

        return $breadcrumbs;
    }

    private function canonicalUrl(string $path): string
    {
        return PublicUrls::path($path);
    }
}
