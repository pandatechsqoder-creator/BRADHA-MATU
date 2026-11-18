<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CmsBanner;
use App\Models\Marketing\CmsCta;
use App\Models\Marketing\CmsMenuItem;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\MarketingRedirect;

final class SeoDiagnosticsService
{
    private const STATIC_PREFIXES = [
        '/media/',
        '/forms/',
        '/preview/',
        '/invitations/',
    ];

    private const STATIC_PATHS = [
        '/',
        '/robots.txt',
        '/sitemap.xml',
        '/login',
        '/register',
        '/forgot-password',
        '/verify',
        '/onboarding',
        '/profile',
        '/settings',
        '/notifications',
        '/support',
        '/app',
        '/platform',
        '/business',
        '/pos',
        '/workforce',
    ];

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $pages = CmsPage::query()
            ->published()
            ->with(['seo', 'currentVersion', 'sections'])
            ->orderBy('slug')
            ->get();

        $documents = [];
        foreach ($pages as $page) {
            $documents[] = $this->document($page);
        }

        $paths = [];
        foreach ($documents as $document) {
            $paths[$document['path']] = true;
        }

        $redirects = MarketingRedirect::query()->where('is_active', true)->get();
        $redirectSources = $redirects->pluck('from_path')->flip();

        $missing = [];
        $duplicateTitles = [];
        $duplicateDescriptions = [];
        $canonicalGroups = [];
        $brokenCanonicals = [];

        foreach ($documents as $document) {
            $problems = [];

            if ($document['title'] === null || $document['title'] === '') {
                $problems[] = 'title';
            }
            if ($document['description'] === null || $document['description'] === '') {
                $problems[] = 'description';
            }
            if ($problems !== []) {
                $missing[] = [
                    'path' => $document['path'],
                    'page' => $document['title'] ?? $document['page'],
                    'problems' => $problems,
                ];
            }

            if ($document['title'] !== null && $document['title'] !== '') {
                $duplicateTitles[strtolower($document['title'])][] = $document['path'];
            }
            if ($document['description'] !== null && $document['description'] !== '') {
                $duplicateDescriptions[strtolower($document['description'])][] = $document['path'];
            }

            if ($document['canonical'] !== null && $document['canonical'] !== '') {
                $canonicalPath = $this->pathOf($document['canonical']);
                $canonicalGroups[$canonicalPath][] = $document['path'];

                if ($canonicalPath !== $document['path'] && ! isset($paths[$canonicalPath])) {
                    $brokenCanonicals[] = ['path' => $document['path'], 'canonical' => $document['canonical']];
                }
            }
        }

        $brokenLinks = [];
        $linkedPaths = [];
        $linkSources = $this->linkSources($documents);

        foreach ($linkSources as $source) {
            $path = $this->pathOf($source['url']);

            if ($path === null) {
                continue;
            }

            $linkedPaths[$path] = true;

            if (isset($paths[$path]) || isset($redirectSources[$path]) || $this->isStaticPath($path)) {
                continue;
            }

            $brokenLinks[] = [
                'from' => $source['from'],
                'url' => $source['url'],
                'path' => $path,
            ];
        }

        $orphans = [];
        foreach ($documents as $document) {
            if ($document['path'] === '/' || ! $document['indexable']) {
                continue;
            }
            if (! isset($linkedPaths[$document['path']])) {
                $orphans[] = ['path' => $document['path'], 'page' => $document['page']];
            }
        }

        $chains = $this->redirectChains($redirects);

        $duplicateTitleIssues = $this->duplicates($duplicateTitles);
        $duplicateDescriptionIssues = $this->duplicates($duplicateDescriptions);
        $canonicalConflicts = [];
        foreach ($canonicalGroups as $canonical => $canonicalPaths) {
            if (count($canonicalPaths) > 1) {
                $canonicalConflicts[] = ['canonical' => $canonical, 'paths' => $canonicalPaths];
            }
        }

        $issues = count($missing)
            + count($duplicateTitleIssues)
            + count($duplicateDescriptionIssues)
            + count($brokenLinks)
            + count($canonicalConflicts)
            + count($brokenCanonicals)
            + count($chains)
            + count($orphans);

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'pages' => count($documents),
                'indexable_pages' => count(array_filter($documents, fn (array $document) => $document['indexable'])),
                'redirects' => $redirects->count(),
                'issues' => $issues,
                'missing_metadata' => count($missing),
                'duplicate_titles' => count($duplicateTitleIssues),
                'duplicate_descriptions' => count($duplicateDescriptionIssues),
                'broken_links' => count($brokenLinks),
                'canonical_conflicts' => count($canonicalConflicts),
                'broken_canonicals' => count($brokenCanonicals),
                'redirect_chains' => count($chains),
                'orphan_pages' => count($orphans),
            ],
            'issues' => [
                'missing_metadata' => $missing,
                'duplicate_titles' => $duplicateTitleIssues,
                'duplicate_descriptions' => $duplicateDescriptionIssues,
                'broken_links' => $brokenLinks,
                'canonical_conflicts' => $canonicalConflicts,
                'broken_canonicals' => $brokenCanonicals,
                'redirect_chains' => $chains,
                'orphan_pages' => $orphans,
            ],
        ];
    }

    /**
     * @return array{path: string, page: string, title: ?string, description: ?string, canonical: ?string, indexable: bool, links: array<int, string>}
     */
    private function document(CmsPage $page): array
    {
        $snapshot = $page->currentVersion?->snapshot;
        $data = $snapshot['page'] ?? [];
        $seo = $snapshot['seo'] ?? [];
        $path = ($data['is_home'] ?? $page->is_home) ? '/' : '/'.($data['slug'] ?? $page->slug);

        $indexable = (bool) config('marketing.indexable')
            && ! ($data['noindex'] ?? $page->noindex)
            && ! ($data['requires_auth'] ?? $page->requires_auth)
            && ($seo['robots_index'] ?? $page->seo?->robots_index ?? true);

        $sections = $snapshot['sections'] ?? $page->sections
            ->map(fn ($section) => ['type' => $section->type, 'content' => $section->content ?? []])
            ->values()
            ->all();

        $links = [];
        foreach ($sections as $section) {
            foreach ($this->urls($section['content'] ?? []) as $url) {
                $links[] = $url;
            }
        }

        return [
            'path' => $path,
            'page' => $data['title'] ?? $page->title,
            'title' => $this->clean($seo['title'] ?? $page->seo?->title),
            'description' => $this->clean($seo['description'] ?? $page->seo?->description),
            'canonical' => $this->clean($seo['canonical_url'] ?? $page->seo?->canonical_url),
            'indexable' => $indexable,
            'links' => $links,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     * @return array<int, array{from: string, url: string}>
     */
    private function linkSources(array $documents): array
    {
        $sources = [];

        foreach ($documents as $document) {
            foreach ($document['links'] as $url) {
                $sources[] = ['from' => $document['path'], 'url' => $url];
            }
        }

        foreach (CmsMenuItem::query()->where('is_visible', true)->with('page:id,slug,is_home')->get() as $item) {
            if ($item->page_id !== null && $item->page !== null) {
                $sources[] = ['from' => 'menu', 'url' => $item->page->is_home ? '/' : '/'.$item->page->slug];
            } elseif ($item->url !== null && $item->url !== '') {
                $sources[] = ['from' => 'menu', 'url' => $item->url];
            }
        }

        foreach (CmsCta::query()->where('is_active', true)->pluck('url') as $url) {
            $sources[] = ['from' => 'cta', 'url' => $url];
        }

        foreach (CmsBanner::query()->whereNotNull('link_url')->pluck('link_url') as $url) {
            $sources[] = ['from' => 'banner', 'url' => $url];
        }

        return $sources;
    }

    /**
     * @param  array<mixed>  $content
     * @return array<int, string>
     */
    private function urls(array $content): array
    {
        $urls = [];

        foreach ($content as $value) {
            if (is_array($value)) {
                $urls = array_merge($urls, $this->urls($value));

                continue;
            }

            if (is_string($value) && str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
                $urls[] = $value;
            }
        }

        return $urls;
    }

    /**
     * @param  array<string, array<int, string>>  $groups
     * @return array<int, array{value: string, paths: array<int, string>}>
     */
    private function duplicates(array $groups): array
    {
        $issues = [];

        foreach ($groups as $value => $paths) {
            if (count($paths) > 1) {
                $issues[] = ['value' => $value, 'paths' => $paths];
            }
        }

        return $issues;
    }

    /**
     * @return array<int, array{from: string, chain: array<int, string>, loop: bool}>
     */
    private function redirectChains($redirects): array
    {
        $bySource = $redirects->keyBy('from_path');
        $issues = [];

        foreach ($redirects as $redirect) {
            $chain = [$redirect->from_path];
            $seen = [$redirect->from_path => true];
            $current = $redirect->to_path;
            $loop = false;

            while (isset($bySource[$current])) {
                if (isset($seen[$current])) {
                    $loop = true;
                    break;
                }

                $seen[$current] = true;
                $chain[] = $current;
                $current = $bySource[$current]->to_path;
            }

            $chain[] = $current;

            if ($loop || count($chain) > 2) {
                $issues[] = ['from' => $redirect->from_path, 'chain' => $chain, 'loop' => $loop];
            }
        }

        return $issues;
    }

    private function isStaticPath(string $path): bool
    {
        if (in_array($path, self::STATIC_PATHS, true)) {
            return true;
        }

        foreach (self::STATIC_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function pathOf(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $host = parse_url($url, PHP_URL_HOST);
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

            if ($host !== null && $appHost !== null && $host !== $appHost) {
                return null;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);

        if ($path === null || $path === false || $path === '') {
            return null;
        }

        $path = '/'.trim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
