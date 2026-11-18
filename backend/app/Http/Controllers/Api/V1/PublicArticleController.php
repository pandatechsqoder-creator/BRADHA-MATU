<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Marketing\BlogCategory;
use App\Models\Marketing\BlogPost;
use App\Services\Marketing\BlogService;
use App\Services\Marketing\StructuredDataService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PublicArticleController extends Controller
{
    public function __construct(
        private readonly BlogService $blog,
        private readonly StructuredDataService $structuredData,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
            'category' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $posts = BlogPost::query()
            ->published()
            ->with(['category', 'tags', 'author', 'featuredAsset'])
            ->when($filters['category'] ?? null, fn ($query, string $slug) => $query
                ->whereHas('category', fn ($where) => $where->where('slug', $slug)))
            ->when($filters['tag'] ?? null, fn ($query, string $slug) => $query
                ->whereHas('tags', fn ($where) => $where->where('slug', $slug)))
            ->when($filters['q'] ?? null, function ($query, string $term): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($term)).'%';
                $query->where(fn ($where) => $where
                    ->where('title', 'like', $like)
                    ->orWhere('excerpt', 'like', $like));
            })
            ->orderByDesc('published_at')
            ->paginate((int) ($filters['per_page'] ?? 12))
            ->withQueryString();

        return ApiResponse::success(
            ['articles' => collect($posts->items())->map(fn (BlogPost $post) => $this->blog->summary($post))->all()],
            200,
            [
                'pagination' => [
                    'current_page' => $posts->currentPage(),
                    'last_page' => $posts->lastPage(),
                    'per_page' => $posts->perPage(),
                    'total' => $posts->total(),
                ],
            ],
        );
    }

    public function show(string $slug): JsonResponse
    {
        $post = BlogPost::query()
            ->published()
            ->where('slug', $slug)
            ->with(['category', 'tags', 'author', 'featuredAsset'])
            ->first();

        if ($post === null) {
            return ApiResponse::error('NOT_FOUND', 'Article not found.', 404);
        }

        $article = $this->blog->view($post);
        $bodyHtml = $post->format === 'markdown'
            ? Str::markdown((string) $post->body)
            : nl2br(e($post->body));

        return ApiResponse::success([
            'article' => $article + [
                'body_html' => $bodyHtml,
                'seo' => [
                    'title' => $article['seo_title'],
                    'description' => $article['description'],
                    'canonical' => $article['canonical'],
                    'robots' => $article['noindex'] ? 'noindex, follow' : 'index, follow',
                ],
            ],
            'structured_data' => $this->structuredData->buildArticle($article),
            'related' => $this->blog->related($post),
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = BlogCategory::query()
            ->withCount(['posts' => fn ($query) => $query->published()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (BlogCategory $category) => (int) $category->posts_count > 0)
            ->map(fn (BlogCategory $category) => [
                'slug' => $category->slug,
                'name' => $category->name,
                'description' => $category->description,
                'post_count' => (int) $category->posts_count,
            ])
            ->values()
            ->all();

        return ApiResponse::success(['categories' => $categories]);
    }
}
