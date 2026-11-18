<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\MediaAsset;
use App\Models\Marketing\ResourceDownload;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ResourceDownloadController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $resources = ResourceDownload::query()->with('asset')->orderBy('title')->get();

        return ApiResponse::success([
            'resource_downloads' => $resources->map(fn (ResourceDownload $resource) => $this->payload($resource))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $resource = ResourceDownload::create($this->attributes($data));

        $this->audit->log('marketing.resource_download.created', $resource, null, null, ['slug' => $resource->slug]);

        return ApiResponse::success(['resource_download' => $this->payload($resource->fresh('asset'))], 201);
    }

    public function update(ResourceDownload $resourceDownload, Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));
        $resourceDownload->update($this->attributes($data, $resourceDownload));

        $this->audit->log('marketing.resource_download.updated', $resourceDownload, null, null, [
            'slug' => $resourceDownload->slug,
        ]);

        return ApiResponse::success(['resource_download' => $this->payload($resourceDownload->fresh('asset'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$presence, 'string', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:800'],
            'media_asset_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('media_assets', 'uuid')],
            'requires_consent' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?ResourceDownload $existing = null): array
    {
        $attributes = [];

        foreach (['title', 'description', 'requires_consent'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        $slugSource = $data['slug'] ?? $data['title'] ?? null;
        if ($slugSource !== null) {
            $slug = trim(Str::slug((string) $slugSource), '-');
            $taken = ResourceDownload::query()
                ->where('slug', $slug)
                ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
                ->exists();

            if ($slug === '' || $taken) {
                abort(422, 'The resource slug is invalid or already in use.');
            }

            $attributes['slug'] = $slug;
        }

        if (array_key_exists('media_asset_id', $data)) {
            $attributes['media_asset_id'] = $data['media_asset_id'] === null
                ? null
                : MediaAsset::query()->where('uuid', $data['media_asset_id'])->value('id');
        }

        if (array_key_exists('is_published', $data)) {
            $attributes['is_published'] = (bool) $data['is_published'];
            $attributes['published_at'] = $data['is_published']
                ? ($existing?->published_at ?? now())
                : null;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ResourceDownload $resource): array
    {
        return [
            'id' => $resource->uuid,
            'slug' => $resource->slug,
            'title' => $resource->title,
            'description' => $resource->description,
            'media_asset_id' => $resource->asset?->uuid,
            'media_asset_url' => $resource->asset?->publicUrl(),
            'requires_consent' => $resource->requires_consent,
            'is_published' => $resource->is_published,
            'download_count' => $resource->download_count,
            'published_at' => $resource->published_at?->toIso8601String(),
            'updated_at' => $resource->updated_at?->toIso8601String(),
        ];
    }
}
