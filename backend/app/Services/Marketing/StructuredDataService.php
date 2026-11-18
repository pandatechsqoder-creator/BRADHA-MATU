<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CmsFaq;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\CmsPageStructuredData;
use App\Models\Marketing\StructuredDataValidationRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class StructuredDataService
{
    public const REQUESTABLE_TYPES = ['faq', 'service', 'software_application', 'article', 'local_business'];

    public const OVERRIDE_FIELDS = ['service_type', 'application_category'];

    private const NODE_TYPES = [
        'organization' => 'Organization',
        'website' => 'WebSite',
        'webpage' => 'WebPage',
        'breadcrumb' => 'BreadcrumbList',
        'faq' => 'FAQPage',
        'service' => 'Service',
        'software_application' => 'SoftwareApplication',
        'article' => 'Article',
        'local_business' => 'LocalBusiness',
    ];

    private const REQUIRED_FIELDS = [
        'organization' => ['name', 'url'],
        'website' => ['name', 'url'],
        'webpage' => ['name', 'url', 'description'],
        'breadcrumb' => ['itemListElement'],
        'faq' => ['mainEntity'],
        'service' => ['name', 'description', 'serviceType'],
        'software_application' => ['name', 'description'],
    ];

    public function __construct(private readonly MarketingSettings $settings) {}

    /**
     * Build the JSON-LD graph for a resolved public page view.
     *
     * @param  array<string, mixed>  $page
     * @return array{@context: string, @graph: array<int, array<string, mixed>>}
     */
    public function build(array $page): array
    {
        $config = $this->settingsForPageId((int) ($page['id'] ?? 0));
        $overrides = $config['overrides'];
        $requested = $config['types'];

        $home = PublicUrls::base().'/';
        $canonical = $page['seo']['canonical'] ?? PublicUrls::path((string) ($page['path'] ?? '/'));
        $organizationId = $home.'#organization';
        $websiteId = $home.'#website';
        $sections = collect($page['sections'] ?? []);
        $faqs = $this->faqs($sections->all());
        $brand = $page['brand'] ?? $this->settings->brand();
        $contact = $page['contact'] ?? $this->settings->contact();
        $social = $page['social'] ?? $this->settings->social();

        $nodes = [
            'organization' => $this->organization($organizationId, $home, $brand, $contact, $social),
            'website' => $this->website($websiteId, $organizationId, $home, $brand),
            'webpage' => $this->webpage($page, $canonical, $organizationId, $websiteId),
            'breadcrumb' => $this->breadcrumbs($page, $canonical, $home),
        ];

        if ($faqs !== []) {
            $nodes['faq'] = $this->faqPage($faqs, $canonical);
        }

        $types = $sections->pluck('type')->all();
        $description = (string) ($page['seo']['description'] ?? '');

        if (($page['type'] ?? 'page') === 'landing'
            && $description !== ''
            && ! in_array('pos_features', $types, true)
            && (in_array('service_grid', $types, true) || in_array('process', $types, true))) {
            $nodes['service'] = $this->service($page, $canonical, $organizationId, $overrides);
        }

        if ($description !== '' && in_array('pos_features', $types, true)) {
            $nodes['software_application'] = $this->softwareApplication($page, $canonical, $organizationId, $overrides);
        }

        // LocalBusiness is intentionally not emitted: no verified location records exist. Article
        // markup for CMS landing pages is replaced by real blog post records in Phase 8.

        return [
            '@context' => 'https://schema.org',
            '@graph' => array_values($nodes),
        ];
    }

    /**
     * Build the JSON-LD graph for a published blog post.
     *
     * @param  array<string, mixed>  $post
     * @return array{@context: string, @graph: array<int, array<string, mixed>>}
     */
    public function buildArticle(array $post): array
    {
        $home = PublicUrls::base().'/';
        $canonical = (string) ($post['canonical'] ?? PublicUrls::path('resources/'.$post['slug']));
        $organizationId = $home.'#organization';
        $websiteId = $home.'#website';
        $brand = $this->settings->brand();
        $contact = $this->settings->contact();

        $breadcrumbs = [['label' => 'Home', 'url' => $home], ['label' => 'Resources', 'url' => PublicUrls::path('resources')]];
        if (! empty($post['category'])) {
            $breadcrumbs[] = [
                'label' => $post['category']['name'],
                'url' => PublicUrls::path('resources/categories/'.$post['category']['slug']),
            ];
        }
        $breadcrumbs[] = ['label' => $post['title'], 'url' => null];

        $nodes = [
            $this->organization($organizationId, $home, $brand, $contact, $this->settings->social()),
            $this->website($websiteId, $organizationId, $home, $brand),
            [
                '@type' => 'WebPage',
                '@id' => $canonical.'#webpage',
                'url' => $canonical,
                'name' => $post['title'],
                'description' => $post['description'],
                'isPartOf' => ['@id' => $websiteId],
                'about' => ['@id' => $organizationId],
                'inLanguage' => 'en',
                'datePublished' => $post['published_at'] ?? null,
                'dateModified' => $post['updated_at'] ?? $post['published_at'] ?? null,
            ],
            $this->breadcrumbs(['breadcrumbs' => $breadcrumbs, 'title' => $post['title']], $canonical, $home),
            $this->article($post, $canonical, $organizationId),
        ];

        return [
            '@context' => 'https://schema.org',
            '@graph' => array_values(array_map(
                fn (array $node) => array_filter($node, fn ($value) => $value !== null && $value !== ''),
                $nodes,
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $graph
     * @return array{status: string, types: array<int, string>, issues: array<int, array{type: string, field: string, message: string}>}
     */
    public function validateArticle(array $post, array $graph): array
    {
        $nodes = [];
        foreach ($graph['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? null) === 'Article') {
                $nodes['article'] = $node;
            }
        }

        $issues = [];
        $article = $nodes['article'] ?? null;

        if ($article === null) {
            $issues[] = $this->issue('article', 'type', 'Article markup is missing from the blog post graph.');
        } else {
            foreach (['headline', 'description', 'datePublished', 'author'] as $field) {
                if ($this->blank($article[$field] ?? null)) {
                    $issues[] = $this->issue('article', $field, "Article is missing its {$field}.");
                }
            }

            if (($article['headline'] ?? null) !== ($post['title'] ?? null)) {
                $issues[] = $this->issue('article', 'headline', 'The Article headline must match the visible post title.');
            }
            if (($article['description'] ?? null) !== ($post['description'] ?? null)) {
                $issues[] = $this->issue('article', 'description', 'The Article description must match the post excerpt.');
            }
            if (($article['author']['name'] ?? null) !== ($post['author'] ?? null)) {
                $issues[] = $this->issue('article', 'author', 'The Article author must match the published author name.');
            }
        }

        foreach ($graph['@graph'] ?? [] as $node) {
            foreach (['aggregateRating', 'review', 'offers', 'price'] as $forbidden) {
                if (array_key_exists($forbidden, $node)) {
                    $issues[] = $this->issue('graph', $forbidden, 'Structured data must not contain invented ratings, reviews, prices or offers.');
                }
            }
        }

        return [
            'status' => $issues === [] ? 'valid' : 'invalid',
            'types' => array_keys($nodes),
            'issues' => $issues,
        ];
    }

    /**
     * Rebuild a public page view from a working snapshot for preview and validation.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function pageFromSnapshot(CmsPage $page, array $snapshot, bool $preview = false): array
    {
        $brand = $this->settings->brand();
        $contact = $this->settings->contact();
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

        $slug = trim((string) ($data['slug'] ?? $page->effectiveSlug()), '/');
        $isHome = (bool) ($data['is_home'] ?? $page->is_home);
        $path = $isHome ? '/' : '/'.$slug;
        $canonical = $preview ? null : ($seoData['canonical_url'] ?? PublicUrls::path($slug));

        $sections = collect($snapshot['sections'] ?? [])
            ->filter(fn ($section) => ($section['is_visible'] ?? true))
            ->map(fn (array $section) => [
                'type' => $section['type'] ?? '',
                'content' => $section['content'] ?? [],
                'settings' => $section['settings'] ?? [],
            ])
            ->values()
            ->all();

        $sections = array_map(function (array $section) use ($page): array {
            if ($section['type'] === 'faq_library') {
                $section['content']['items'] = $this->libraryFaqs($page);
            }

            return $section;
        }, $sections);

        return [
            'id' => $page->id,
            'slug' => $slug,
            'path' => $path,
            'title' => $data['title'] ?? $page->title,
            'type' => $data['type'] ?? $page->type,
            'is_home' => $isHome,
            'locale' => $data['locale'] ?? $page->locale,
            'sections' => $sections,
            'breadcrumbs' => $this->breadcrumbsFromPath($slug, $data['title'] ?? $page->title),
            'brand' => $brand,
            'contact' => $contact,
            'social' => $this->settings->social(),
            'preview' => $preview,
            'seo' => [
                'title' => $title,
                'description' => $description,
                'canonical' => $canonical,
            ],
            'meta' => [
                'last_modified' => $page->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Validate a graph against the resolved page content.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $graph
     * @return array{status: string, types: array<int, string>, issues: array<int, array{type: string, field: string, message: string}>}
     */
    public function validate(array $page, array $graph, ?CmsPage $model = null): array
    {
        $nodes = [];
        foreach ($graph['@graph'] ?? [] as $node) {
            $key = array_search($node['@type'] ?? null, self::NODE_TYPES, true);
            if ($key !== false) {
                $nodes[$key] = $node;
            }
        }

        $config = $model !== null
            ? $this->settingsFor($model)
            : $this->settingsForPageId((int) ($page['id'] ?? 0));
        $requested = $config['types'];
        $issues = [];

        foreach (self::REQUIRED_FIELDS as $key => $fields) {
            if (! isset($nodes[$key])) {
                continue;
            }
            foreach ($fields as $field) {
                if ($this->blank($nodes[$key][$field] ?? null)) {
                    $issues[] = $this->issue($key, $field, ucfirst(str_replace('_', ' ', $key))." is missing its {$field}.");
                }
            }
        }

        if (isset($nodes['webpage'])) {
            if (($nodes['webpage']['name'] ?? null) !== ($page['title'] ?? null)) {
                $issues[] = $this->issue('webpage', 'name', 'The WebPage name must match the visible page title.');
            }
            if (($nodes['webpage']['description'] ?? null) !== ($page['seo']['description'] ?? null)) {
                $issues[] = $this->issue('webpage', 'description', 'The WebPage description must match the page meta description.');
            }
            if (($page['seo']['canonical'] ?? null) !== null
                && ($nodes['webpage']['url'] ?? null) !== $page['seo']['canonical']) {
                $issues[] = $this->issue('webpage', 'url', 'The WebPage URL must match the canonical URL.');
            }
        }

        if (isset($nodes['breadcrumb'])) {
            $items = $nodes['breadcrumb']['itemListElement'] ?? [];
            $last = is_array($items) ? end($items) : null;
            if (! is_array($last) || ($last['name'] ?? null) !== ($page['title'] ?? null)) {
                $issues[] = $this->issue('breadcrumb', 'itemListElement', 'The breadcrumb must end with the visible page title.');
            }
            if (! is_array($items) || ($items[0]['name'] ?? null) !== 'Home') {
                $issues[] = $this->issue('breadcrumb', 'itemListElement', 'The breadcrumb must start at Home.');
            }
        }

        if (isset($nodes['faq'])) {
            $visible = array_map(
                fn (array $faq) => ['question' => $faq['question'], 'answer' => $faq['answer']],
                $this->faqs($page['sections'] ?? []),
            );
            $rendered = array_map(fn (array $question) => [
                'question' => $question['name'] ?? '',
                'answer' => $question['acceptedAnswer']['text'] ?? '',
            ], $nodes['faq']['mainEntity'] ?? []);

            if ($visible !== $rendered) {
                $issues[] = $this->issue('faq', 'mainEntity', 'FAQPage questions must match the visible FAQs exactly.');
            }

            $questions = array_column($rendered, 'question');
            if (count($questions) !== count(array_unique($questions))) {
                $issues[] = $this->issue('faq', 'mainEntity', 'FAQPage contains duplicate questions.');
            }
        }

        if (isset($nodes['service']) && ($nodes['service']['name'] ?? null) !== ($page['title'] ?? null)) {
            $issues[] = $this->issue('service', 'name', 'The Service name must match the visible page title.');
        }

        if (isset($nodes['software_application'])
            && ($nodes['software_application']['name'] ?? null) !== ($page['title'] ?? null)) {
            $issues[] = $this->issue('software_application', 'name', 'The SoftwareApplication name must match the visible page title.');
        }

        if (in_array('article', $requested, true) && ! isset($nodes['article'])) {
            $issues[] = $this->issue('article', 'type', 'Article/BlogPosting markup is generated from blog posts; publish the content as a blog post instead.');
        }

        if (in_array('local_business', $requested, true) && ! isset($nodes['local_business'])) {
            $issues[] = $this->issue('local_business', 'location_id', 'LocalBusiness requires a verified location record; no verified location is configured.');
        }

        if (in_array('service', $requested, true) && ! isset($nodes['service'])) {
            $issues[] = $this->issue('service', 'description', 'Service markup needs a landing page with a description and service sections.');
        }

        if (in_array('software_application', $requested, true) && ! isset($nodes['software_application'])) {
            $issues[] = $this->issue('software_application', 'type', 'SoftwareApplication markup needs a visible POS feature section.');
        }

        if (in_array('faq', $requested, true) && ! isset($nodes['faq'])) {
            $issues[] = $this->issue('faq', 'mainEntity', 'FAQPage markup needs at least one visible FAQ.');
        }

        foreach ($graph['@graph'] ?? [] as $node) {
            foreach (['aggregateRating', 'review', 'offers', 'price'] as $forbidden) {
                if (array_key_exists($forbidden, $node)) {
                    $issues[] = $this->issue('graph', $forbidden, 'Structured data must not contain invented ratings, reviews, prices or offers.');
                }
            }
        }

        return [
            'status' => $issues === [] ? 'valid' : 'invalid',
            'types' => array_keys($nodes),
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{page: array<string, mixed>, graph: array<string, mixed>, validation: array<string, mixed>}
     */
    public function validateSnapshot(CmsPage $page, array $snapshot): array
    {
        $pageView = $this->pageFromSnapshot($page, $snapshot, preview: true);
        $graph = $this->build($pageView);

        return [
            'page' => $pageView,
            'graph' => $graph,
            'validation' => $this->validate($pageView, $graph, $page),
        ];
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  array<string, mixed>  $graph
     */
    public function recordRun(CmsPage $page, array $validation, array $graph, ?User $user): StructuredDataValidationRun
    {
        return StructuredDataValidationRun::create([
            'page_id' => $page->id,
            'status' => $validation['status'],
            'types' => $validation['types'],
            'issues' => $validation['issues'],
            'json_ld' => json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'validated_by' => $user?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function runs(CmsPage $page): array
    {
        return StructuredDataValidationRun::query()
            ->where('page_id', $page->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (StructuredDataValidationRun $run) => [
                'id' => $run->uuid,
                'status' => $run->status,
                'types' => $run->types,
                'issues' => $run->issues,
                'json_ld' => json_decode($run->json_ld, true),
                'validated_by' => $run->validated_by,
                'created_at' => $run->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array{types: array<int, string>, overrides: array<string, string>}
     */
    public function settingsFor(CmsPage $page): array
    {
        return $this->settingsForPageId($page->id);
    }

    /**
     * @param  array<int, string>  $types
     * @param  array<string, string>  $overrides
     */
    public function saveSettings(CmsPage $page, array $types, array $overrides): CmsPageStructuredData
    {
        $types = array_values(array_intersect(array_unique($types), self::REQUESTABLE_TYPES));
        $overrides = array_intersect_key($overrides, array_flip(self::OVERRIDE_FIELDS));

        return CmsPageStructuredData::updateOrCreate(
            ['page_id' => $page->id],
            ['types' => $types, 'overrides' => $overrides],
        );
    }

    /** @param array<string, mixed> $validation */
    public function failureMessage(array $validation): string
    {
        $first = $validation['issues'][0]['message'] ?? 'Structured data validation failed.';

        return 'Structured data must be valid before publishing. '.$first;
    }

    /**
     * @return array{types: array<int, string>, overrides: array<string, string>}
     */
    private function settingsForPageId(int $pageId): array
    {
        if ($pageId <= 0) {
            return ['types' => [], 'overrides' => []];
        }

        $row = CmsPageStructuredData::query()->where('page_id', $pageId)->first();

        if ($row === null) {
            return ['types' => [], 'overrides' => []];
        }

        return [
            'types' => array_values(array_intersect($row->types ?? [], self::REQUESTABLE_TYPES)),
            'overrides' => array_intersect_key($row->overrides ?? [], array_flip(self::OVERRIDE_FIELDS)),
        ];
    }

    /**
     * @param  array<string, mixed>  $brand
     * @param  array<string, mixed>  $contact
     * @param  array<string, ?string>  $social
     * @return array<string, mixed>
     */
    private function organization(string $id, string $home, array $brand, array $contact, array $social): array
    {
        $node = [
            '@type' => 'Organization',
            '@id' => $id,
            'name' => $brand['name'],
            'url' => $home,
            'description' => $brand['tagline'] ?: $brand['short_description'],
        ];

        if (! empty($contact['email'])) {
            $node['email'] = $contact['email'];
        }
        if (! empty($contact['phone'])) {
            $node['telephone'] = $contact['phone'];
        }
        if (! empty($contact['address'])) {
            $node['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $contact['address'],
            ];
        }
        if ($social !== []) {
            $node['sameAs'] = array_values($social);
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    private function website(string $id, string $organizationId, string $home, array $brand): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => $id,
            'url' => $home,
            'name' => $brand['name'],
            'description' => $brand['short_description'],
            'inLanguage' => 'en',
            'publisher' => ['@id' => $organizationId],
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function webpage(array $page, string $canonical, string $organizationId, string $websiteId): array
    {
        $node = [
            '@type' => 'WebPage',
            '@id' => $canonical.'#webpage',
            'url' => $canonical,
            'name' => $page['title'] ?? '',
            'description' => $page['seo']['description'] ?? '',
            'isPartOf' => ['@id' => $websiteId],
            'about' => ['@id' => $organizationId],
            'inLanguage' => 'en',
        ];

        $modified = $page['meta']['last_modified'] ?? null;
        if (is_string($modified) && $modified !== '') {
            $node['dateModified'] = $modified;
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function breadcrumbs(array $page, string $canonical, string $home): array
    {
        $breadcrumbs = $page['breadcrumbs'] ?? [];

        if ($breadcrumbs === []) {
            $breadcrumbs = [
                ['label' => 'Home', 'url' => $home],
                ['label' => $page['title'] ?? '', 'url' => null],
            ];
        }

        $items = [];
        foreach (array_values($breadcrumbs) as $index => $crumb) {
            $item = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['label'] ?? '',
            ];
            if (! empty($crumb['url'])) {
                $item['item'] = $crumb['url'];
            }
            $items[] = $item;
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical.'#breadcrumb',
            'itemListElement' => $items,
        ];
    }

    /**
     * @param  array<int, array{question: string, answer: string}>  $faqs
     * @return array<string, mixed>
     */
    private function faqPage(array $faqs, string $canonical): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $canonical.'#faq',
            'mainEntity' => array_map(fn (array $faq) => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ], $faqs),
        ];
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function article(array $post, string $canonical, string $organizationId): array
    {
        $node = [
            '@type' => 'Article',
            '@id' => $canonical.'#article',
            'headline' => $post['title'],
            'description' => $post['description'],
            'url' => $canonical,
            'datePublished' => $post['published_at'] ?? null,
            'dateModified' => $post['updated_at'] ?? $post['published_at'] ?? null,
            'author' => [
                '@type' => 'Person',
                'name' => $post['author'],
            ],
            'publisher' => ['@id' => $organizationId],
            'mainEntityOfPage' => ['@id' => $canonical.'#webpage'],
            'inLanguage' => 'en',
        ];

        if (! empty($post['category']['name'])) {
            $node['articleSection'] = $post['category']['name'];
        }

        if (! empty($post['tags'])) {
            $node['keywords'] = implode(', ', array_map(
                fn (array $tag) => (string) ($tag['name'] ?? ''),
                $post['tags'],
            ));
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function service(array $page, string $canonical, string $organizationId, array $overrides): array
    {
        return [
            '@type' => 'Service',
            '@id' => $canonical.'#service',
            'name' => $page['title'] ?? '',
            'serviceType' => $overrides['service_type'] ?? ($page['title'] ?? ''),
            'description' => $page['seo']['description'] ?? '',
            'url' => $canonical,
            'provider' => ['@id' => $organizationId],
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function softwareApplication(array $page, string $canonical, string $organizationId, array $overrides): array
    {
        return [
            '@type' => 'SoftwareApplication',
            '@id' => $canonical.'#software',
            'name' => $page['title'] ?? '',
            'applicationCategory' => $overrides['application_category'] ?? 'BusinessApplication',
            'description' => $page['seo']['description'] ?? '',
            'url' => $canonical,
            'publisher' => ['@id' => $organizationId],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array{question: string, answer: string}>
     */
    private function faqs(array $sections): array
    {
        $faqs = [];

        foreach ($sections as $section) {
            if (! in_array($section['type'] ?? null, ['faq', 'faq_library'], true)) {
                continue;
            }

            foreach ($section['content']['items'] ?? [] as $item) {
                if (is_array($item) && ! empty($item['question'])) {
                    $faqs[] = [
                        'question' => (string) $item['question'],
                        'answer' => (string) ($item['answer'] ?? ''),
                    ];
                }
            }
        }

        return $faqs;
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    private function libraryFaqs(CmsPage $page): array
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
     * @return array<int, array{label: string, url: ?string}>
     */
    private function breadcrumbsFromPath(string $slug, string $title): array
    {
        $crumbs = [['label' => 'Home', 'url' => PublicUrls::base().'/']];

        if ($slug === '') {
            return $crumbs;
        }

        $segments = array_slice(explode('/', $slug), 0, -1);
        $path = null;
        foreach ($segments as $segment) {
            $path = $path === null ? $segment : $path.'/'.$segment;
            $ancestor = CmsPage::query()->published()->where('slug', $path)->first();
            if ($ancestor !== null) {
                $crumbs[] = ['label' => $ancestor->title, 'url' => PublicUrls::path($ancestor->slug)];
            }
        }

        $crumbs[] = ['label' => $title, 'url' => null];

        return $crumbs;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @return array{type: string, field: string, message: string}
     */
    private function issue(string $type, string $field, string $message): array
    {
        return ['type' => $type, 'field' => $field, 'message' => $message];
    }
}
