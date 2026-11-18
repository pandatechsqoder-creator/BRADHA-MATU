<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\BlogCategory;
use App\Models\Marketing\BlogTag;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class BlogTaxonomyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function categories(): JsonResponse
    {
        $categories = BlogCategory::query()
            ->withCount(['posts' => fn ($query) => $query->published()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'categories' => $categories->map(fn (BlogCategory $category) => $this->categoryPayload($category))->all(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:600'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('blog_categories', 'uuid')],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        $category = BlogCategory::create($this->categoryAttributes($data));
        $this->audit->log('marketing.post_category.created', $category, null, null, ['slug' => $category->slug]);

        return ApiResponse::success(['category' => $this->categoryPayload($category)], 201);
    }

    public function updateCategory(BlogCategory $category, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:600'],
            'parent_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('blog_categories', 'uuid')],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        $category->update($this->categoryAttributes($data, $category));
        $this->audit->log('marketing.post_category.updated', $category, null, null, ['slug' => $category->slug]);

        return ApiResponse::success(['category' => $this->categoryPayload($category->fresh())]);
    }

    public function tags(): JsonResponse
    {
        $tags = BlogTag::query()->withCount('posts')->orderBy('name')->get();

        return ApiResponse::success([
            'tags' => $tags->map(fn (BlogTag $tag) => $this->tagPayload($tag))->all(),
        ]);
    }

    public function storeTag(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $tag = BlogTag::create($this->tagAttributes($data));
        $this->audit->log('marketing.post_tag.created', $tag, null, null, ['slug' => $tag->slug]);

        return ApiResponse::success(['tag' => $this->tagPayload($tag)], 201);
    }

    public function updateTag(BlogTag $tag, Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $tag->update($this->tagAttributes($data, $tag));
        $this->audit->log('marketing.post_tag.updated', $tag, null, null, ['slug' => $tag->slug]);

        return ApiResponse::success(['tag' => $this->tagPayload($tag->fresh())]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function categoryAttributes(array $data, ?BlogCategory $existing = null): array
    {
        $attributes = [];

        foreach (['name', 'description', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        $slugSource = $data['slug'] ?? $data['name'] ?? null;
        if ($slugSource !== null) {
            $slug = trim(Str::slug((string) $slugSource), '-');
            $taken = BlogCategory::query()
                ->where('slug', $slug)
                ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($slug === '' || $taken) {
                abort(422, 'The category slug is invalid or already in use.');
            }

            $attributes['slug'] = $slug;
        }

        if (array_key_exists('parent_id', $data)) {
            $parentId = $data['parent_id'] === null
                ? null
                : BlogCategory::query()->where('uuid', $data['parent_id'])->value('id');

            if ($existing !== null && (int) $parentId === (int) $existing->id) {
                abort(422, 'A category cannot be its own parent.');
            }

            $attributes['parent_id'] = $parentId;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function tagAttributes(array $data, ?BlogTag $existing = null): array
    {
        $attributes = [];

        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }

        $slugSource = $data['slug'] ?? $data['name'] ?? null;
        if ($slugSource !== null) {
            $slug = trim(Str::slug((string) $slugSource), '-');
            $taken = BlogTag::query()
                ->where('slug', $slug)
                ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($slug === '' || $taken) {
                abort(422, 'The tag slug is invalid or already in use.');
            }

            $attributes['slug'] = $slug;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryPayload(BlogCategory $category): array
    {
        return [
            'id' => $category->uuid,
            'slug' => $category->slug,
            'name' => $category->name,
            'description' => $category->description,
            'parent_id' => $category->parent?->uuid,
            'sort_order' => $category->sort_order,
            'post_count' => (int) ($category->posts_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tagPayload(BlogTag $tag): array
    {
        return [
            'id' => $tag->uuid,
            'slug' => $tag->slug,
            'name' => $tag->name,
            'post_count' => (int) ($tag->posts_count ?? 0),
        ];
    }
}
