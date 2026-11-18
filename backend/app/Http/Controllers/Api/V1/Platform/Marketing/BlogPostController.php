<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\BlogPost;
use App\Services\Marketing\BlogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BlogPostController extends Controller
{
    private const STATUSES = ['draft', 'review', 'approved', 'scheduled', 'published', 'archived'];

    public function __construct(private readonly BlogService $posts) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'category' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        return ApiResponse::success($this->posts->adminList($filters));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $post = $this->posts->create($data, $request->user());

        return ApiResponse::success(['post' => $this->posts->present($post)], 201);
    }

    public function show(BlogPost $post): JsonResponse
    {
        return ApiResponse::success(['post' => $this->posts->present($post->load(['category', 'tags', 'author', 'relatedPosts']))]);
    }

    public function update(BlogPost $post, Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));
        $post = $this->posts->update($post, $data, $request->user());

        return ApiResponse::success(['post' => $this->posts->present($post)]);
    }

    public function destroy(BlogPost $post): JsonResponse
    {
        $this->posts->delete($post);

        return ApiResponse::success(['deleted' => true]);
    }

    public function submitReview(BlogPost $post, Request $request): JsonResponse
    {
        return $this->transition($post, 'submit-review', $request);
    }

    public function approve(BlogPost $post, Request $request): JsonResponse
    {
        return $this->transition($post, 'approve', $request);
    }

    public function reject(BlogPost $post, Request $request): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->transition($post, 'reject', $request);
    }

    public function schedule(BlogPost $post, Request $request): JsonResponse
    {
        $request->validate(['scheduled_at' => ['required', 'date', 'after:now']]);

        return $this->transition($post, 'schedule', $request);
    }

    public function publish(BlogPost $post, Request $request): JsonResponse
    {
        $request->validate(['change_summary' => ['nullable', 'string', 'max:255']]);

        return $this->transition($post, 'publish', $request);
    }

    public function unpublish(BlogPost $post, Request $request): JsonResponse
    {
        return $this->transition($post, 'unpublish', $request);
    }

    public function archive(BlogPost $post, Request $request): JsonResponse
    {
        return $this->transition($post, 'archive', $request);
    }

    private function transition(BlogPost $post, string $action, Request $request): JsonResponse
    {
        $post = $this->posts->transition($post, $action, $request->user(), $request->all());

        return ApiResponse::success(['post' => $this->posts->present($post)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$presence, 'string', 'max:200'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:200'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:600'],
            'body' => [$presence, 'string', 'max:100000'],
            'format' => ['sometimes', Rule::in(['markdown', 'plain'])],
            'category_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('blog_categories', 'uuid')],
            'author_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('users', 'uuid')],
            'author_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tags' => ['sometimes', 'array', 'max:12'],
            'tags.*' => ['string', 'max:60'],
            'related_ids' => ['sometimes', 'array', 'max:6'],
            'related_ids.*' => ['string', 'size:26', Rule::exists('blog_posts', 'uuid')->whereNull('deleted_at')],
            'reading_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'canonical_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'og_title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'og_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'robots_index' => ['sometimes', 'boolean'],
            'robots_follow' => ['sometimes', 'boolean'],
            'noindex' => ['sometimes', 'boolean'],
        ];
    }
}
