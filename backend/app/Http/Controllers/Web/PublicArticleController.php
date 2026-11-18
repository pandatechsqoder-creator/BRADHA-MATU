<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Marketing\BlogCategory;
use App\Models\Marketing\BlogPost;
use App\Models\Marketing\BlogTag;
use App\Models\Marketing\ResourceDownload;
use App\Services\Marketing\BlogService;
use App\Services\Marketing\MarketingSettings;
use App\Services\Marketing\PublicPageResolver;
use App\Services\Marketing\PublicUrls;
use App\Services\Marketing\StructuredDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PublicArticleController extends Controller
{
    public function __construct(
        private readonly BlogService $blog,
        private readonly MarketingSettings $settings,
        private readonly PublicPageResolver $resolver,
        private readonly StructuredDataService $structuredData,
    ) {}

    public function index(Request $request)
    {
        $page = $this->resolver->find('resources') ?? $this->pageShell('Resources', PublicUrls::path('resources'));
        $posts = $this->query($request)->paginate(9)->withQueryString();
        $filtered = $request->filled('q') || $request->filled('category') || $request->filled('tag');

        if ($filtered) {
            $page['seo']['robots'] = 'noindex, follow';
        }

        $page['structured_data'] = $this->structuredData->build($page);

        return view('public.resources.index', [
            'page' => $page,
            'articles' => collect($posts->items())->map(fn (BlogPost $post) => $this->blog->summary($post))->all(),
            'categories' => $this->categories(),
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'category' => (string) $request->query('category', ''),
                'tag' => (string) $request->query('tag', ''),
            ],
            'paginator' => $posts,
        ]);
    }

    public function category(string $slug)
    {
        $category = BlogCategory::query()->where('slug', $slug)->firstOrFail();
        $posts = BlogPost::query()
            ->published()
            ->where('category_id', $category->id)
            ->with(['category', 'tags', 'author', 'featuredAsset'])
            ->orderByDesc('published_at')
            ->paginate(9)
            ->withQueryString();

        $page = $this->pageShell(
            'Resources — '.$category->name,
            PublicUrls::path('resources/categories/'.$category->slug),
            'Articles in '.$category->name.'.',
        );

        return view('public.resources.index', [
            'page' => $page,
            'articles' => collect($posts->items())->map(fn (BlogPost $post) => $this->blog->summary($post))->all(),
            'categories' => $this->categories(),
            'filters' => ['q' => '', 'category' => $category->slug, 'tag' => ''],
            'paginator' => $posts,
        ]);
    }

    public function tag(string $slug)
    {
        $tag = BlogTag::query()->where('slug', $slug)->firstOrFail();
        $posts = BlogPost::query()
            ->published()
            ->whereHas('tags', fn ($query) => $query->whereKey($tag->id))
            ->with(['category', 'tags', 'author', 'featuredAsset'])
            ->orderByDesc('published_at')
            ->paginate(9)
            ->withQueryString();

        $page = $this->pageShell(
            'Tag — '.$tag->name,
            PublicUrls::path('resources/tags/'.$tag->slug),
            'Articles tagged '.$tag->name.'.',
        );
        $page['seo']['robots'] = 'noindex, follow';

        return view('public.resources.index', [
            'page' => $page,
            'articles' => collect($posts->items())->map(fn (BlogPost $post) => $this->blog->summary($post))->all(),
            'categories' => $this->categories(),
            'filters' => ['q' => '', 'category' => '', 'tag' => $tag->slug],
            'paginator' => $posts,
        ]);
    }

    public function show(string $slug)
    {
        $post = BlogPost::query()
            ->published()
            ->where('slug', $slug)
            ->with(['category', 'tags', 'author', 'featuredAsset'])
            ->firstOrFail();

        $article = $this->blog->view($post);
        $image = $article['image_url'];

        $page = $this->pageShell(
            $article['seo_title'],
            $article['canonical'],
            $article['description'],
            sections: [],
            titleSuffix: true,
        );

        $page['breadcrumbs'] = $this->breadcrumbs($post);
        $page['seo']['og_type'] = 'article';
        $page['seo']['og_title'] = $article['seo_title'];
        $page['seo']['og_description'] = $article['description'];
        if ($image !== null) {
            $page['seo']['og_image'] = $image;
        }
        if ($article['noindex']) {
            $page['seo']['robots'] = 'noindex, '.(($article['robots_follow'] ?? true) ? 'follow' : 'nofollow');
        }

        $page['structured_data'] = $this->structuredData->buildArticle($article);
        $page['meta']['last_modified'] = $article['updated_at'];

        $bodyHtml = $post->format === 'markdown'
            ? Str::markdown((string) $post->body)
            : nl2br(e($post->body));

        return view('public.resources.show', [
            'page' => $page,
            'article' => $article,
            'body_html' => $bodyHtml,
            'related' => $this->blog->related($post),
        ]);
    }

    public function downloadPage(string $slug)
    {
        $resource = $this->publishedResource($slug);

        $page = $this->pageShell(
            $resource->title,
            PublicUrls::path('resources/downloads/'.$resource->slug),
            (string) $resource->description,
        );
        $page['seo']['robots'] = 'noindex, follow';
        $page['breadcrumbs'] = [
            ['label' => 'Home', 'url' => url('/')],
            ['label' => 'Resources', 'url' => url('/resources')],
            ['label' => $resource->title, 'url' => null],
        ];

        return view('public.resources.download', [
            'page' => $page,
            'resource' => $resource,
        ]);
    }

    public function download(string $slug, Request $request): RedirectResponse
    {
        $resource = $this->publishedResource($slug);

        if ($resource->requires_consent) {
            $request->validate([
                'consent' => ['required', 'in:accepted'],
            ], [
                'consent.in' => 'Confirm that you agree to the download terms before continuing.',
                'consent.required' => 'Confirm that you agree to the download terms before continuing.',
            ]);
        }

        $asset = $resource->asset;

        if ($asset === null) {
            abort(404);
        }

        $resource->increment('download_count');

        return redirect()->away($asset->publicUrl());
    }

    public function feed()
    {
        $posts = BlogPost::query()
            ->published()
            ->with(['category', 'author'])
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();

        return response()
            ->view('public.rss', [
                'brand' => $this->settings->brand(),
                'posts' => $posts->map(fn (BlogPost $post) => [
                    'title' => $post->title,
                    'url' => PublicUrls::path('resources/'.$post->slug),
                    'description' => $post->effectiveExcerpt(280),
                    'published_at' => $post->published_at?->toRssString(),
                    'author' => $post->authorLabel(),
                    'category' => $post->category?->name,
                ])->all(),
            ])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    private function query(Request $request)
    {
        return BlogPost::query()
            ->published()
            ->with(['category', 'tags', 'author', 'featuredAsset'])
            ->when($request->query('category'), function ($query, string $slug): void {
                $query->whereHas('category', fn ($where) => $where->where('slug', $slug));
            })
            ->when($request->query('tag'), function ($query, string $slug): void {
                $query->whereHas('tags', fn ($where) => $where->where('slug', $slug));
            })
            ->when($request->query('q'), function ($query, string $term): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($term)).'%';
                $query->where(fn ($where) => $where
                    ->where('title', 'like', $like)
                    ->orWhere('excerpt', 'like', $like)
                    ->orWhere('body', 'like', $like));
            })
            ->orderByDesc('published_at');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categories(): array
    {
        return BlogCategory::query()
            ->withCount(['posts' => fn ($query) => $query->published()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (BlogCategory $category) => (int) $category->posts_count > 0)
            ->map(fn (BlogCategory $category) => [
                'slug' => $category->slug,
                'name' => $category->name,
                'post_count' => (int) $category->posts_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, url: ?string}>
     */
    private function breadcrumbs(BlogPost $post): array
    {
        $crumbs = [
            ['label' => 'Home', 'url' => url('/')],
            ['label' => 'Resources', 'url' => url('/resources')],
        ];

        if ($post->category !== null) {
            $crumbs[] = [
                'label' => $post->category->name,
                'url' => url('/resources/categories/'.$post->category->slug),
            ];
        }

        $crumbs[] = ['label' => $post->title, 'url' => null];

        return $crumbs;
    }

    private function publishedResource(string $slug): ResourceDownload
    {
        return ResourceDownload::query()
            ->with('asset')
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();
    }

    /**
     * @param  array<int, array{type: string, content: array<string, mixed>, settings: array<string, mixed>}>  $sections
     * @return array<string, mixed>
     */
    private function pageShell(
        string $title,
        string $canonical,
        ?string $description = null,
        array $sections = [],
        bool $titleSuffix = false,
    ): array {
        $brand = $this->settings->brand();
        $description = $description ?: $this->settings->defaultDescription() ?: $brand['short_description'];
        $resolvedTitle = $title;
        $suffix = $this->settings->titleSuffix();

        if ($titleSuffix && $suffix !== '' && ! Str::contains($resolvedTitle, $suffix)) {
            $resolvedTitle .= $suffix;
        }

        $indexable = (bool) config('marketing.indexable');

        return [
            'id' => 0,
            'slug' => ltrim($canonical, '/'),
            'path' => parse_url($canonical, PHP_URL_PATH) ?: '/resources',
            'title' => $title,
            'type' => 'page',
            'template' => 'public.resources.index',
            'is_home' => false,
            'sections' => $sections,
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => url('/')],
                ['label' => 'Resources', 'url' => url('/resources')],
            ],
            'brand' => $brand,
            'contact' => $this->settings->contact(),
            'social' => $this->settings->social(),
            'preview' => false,
            'seo' => [
                'title' => $resolvedTitle,
                'description' => $description,
                'canonical' => $canonical,
                'robots' => $indexable ? 'index, follow' : 'noindex, follow',
                'og_title' => $resolvedTitle,
                'og_description' => $description,
                'og_type' => 'website',
                'og_site_name' => $brand['name'],
                'twitter_card' => 'summary_large_image',
            ],
            'meta' => [
                'priority' => 0.6,
                'change_frequency' => 'weekly',
                'last_modified' => now()->toIso8601String(),
            ],
        ];
    }
}
