<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Marketing\BlogCategory;
use App\Models\Marketing\BlogPost;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\ResourceDownload;
use App\Services\Marketing\PublicUrls;
use Illuminate\Http\Response;

final class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $pages = CmsPage::query()
            ->published()
            ->with(['seo', 'currentVersion'])
            ->get()
            ->filter(function (CmsPage $page): bool {
                $snapshot = $page->currentVersion?->snapshot;
                $data = $snapshot['page'] ?? [];
                $seo = $snapshot['seo'] ?? [];

                return ! ($data['noindex'] ?? $page->noindex)
                    && ! ($data['requires_auth'] ?? $page->requires_auth)
                    && ($seo['robots_index'] ?? $page->seo?->robots_index ?? true);
            })
            ->sortBy([['is_home', 'desc'], ['slug', 'asc']])
            ->values();

        $urls = $pages->map(function (CmsPage $page) {
            $snapshot = $page->currentVersion?->snapshot;
            $data = $snapshot['page'] ?? [];
            $seo = $snapshot['seo'] ?? [];
            $slug = $data['slug'] ?? $page->slug;
            $isHome = (bool) ($data['is_home'] ?? $page->is_home);

            return [
                'loc' => PublicUrls::path($isHome ? '' : $slug),
                'lastmod' => ($page->currentVersion?->created_at ?? $page->updated_at ?? $page->published_at)?->toAtomString(),
                'changefreq' => $seo['change_frequency'] ?? ($page->seo?->change_frequency ?: ($isHome ? 'weekly' : 'monthly')),
                'priority' => number_format((float) ($seo['priority'] ?? $page->seo?->priority ?? ($isHome ? 1.0 : 0.5)), 1, '.', ''),
            ];
        })->all();

        $articles = BlogPost::query()
            ->indexable()
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (BlogPost $post) => [
                'loc' => PublicUrls::path('resources/'.$post->slug),
                'lastmod' => ($post->updated_at ?? $post->published_at)?->toAtomString(),
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ])->all();

        $categories = BlogCategory::query()
            ->whereHas('posts', fn ($query) => $query->published())
            ->orderBy('sort_order')
            ->get()
            ->map(fn (BlogCategory $category) => [
                'loc' => PublicUrls::path('resources/categories/'.$category->slug),
                'lastmod' => $category->updated_at?->toAtomString(),
                'changefreq' => 'weekly',
                'priority' => '0.4',
            ])->all();

        $downloads = ResourceDownload::query()
            ->where('is_published', true)
            ->orderBy('title')
            ->get()
            ->map(fn (ResourceDownload $resource) => [
                'loc' => PublicUrls::path('resources/downloads/'.$resource->slug),
                'lastmod' => $resource->updated_at?->toAtomString(),
                'changefreq' => 'monthly',
                'priority' => '0.3',
            ])->all();

        $urls = array_merge($urls, $articles, $categories, $downloads);

        return response()
            ->view('public.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
