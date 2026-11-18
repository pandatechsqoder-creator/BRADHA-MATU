<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\MediaAsset;
use App\Models\Marketing\MediaFolder;
use App\Services\Audit\AuditLogger;
use App\Services\Marketing\MediaLibraryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MediaAssetController extends Controller
{
    public function __construct(
        private readonly MediaLibraryService $media,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'folder' => ['nullable', 'string', 'size:26'],
            'mime' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $assets = $this->media->assets($filters);

        return ApiResponse::success(
            ['assets' => collect($assets->items())->map(fn (MediaAsset $asset) => $this->media->payload($asset))->all()],
            200,
            [
                'pagination' => [
                    'page' => $assets->currentPage(),
                    'per_page' => $assets->perPage(),
                    'total' => $assets->total(),
                    'last_page' => $assets->lastPage(),
                ],
            ],
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->uploadRules());
        $asset = $this->media->upload($request->file('file'), $data, $request->user());

        $this->audit->log('marketing.media.uploaded', $asset, null, null, [
            'filename' => $asset->filename,
            'checksum' => $asset->checksum,
        ]);

        return ApiResponse::success(
            [
                'asset' => $this->media->payload($asset->load('folder')),
                'duplicate' => ! $asset->wasRecentlyCreated,
            ],
            201,
        );
    }

    public function show(MediaAsset $asset): JsonResponse
    {
        $asset->load(['folder', 'versions', 'usages']);

        return ApiResponse::success([
            'asset' => $this->media->payload($asset),
            'versions' => $asset->versions->map(fn ($version) => [
                'version' => $version->version,
                'size_bytes' => $version->size_bytes,
                'checksum' => $version->checksum,
                'created_at' => $version->created_at?->toIso8601String(),
            ])->all(),
            'usages' => $this->usagePayloads($asset),
        ]);
    }

    public function update(MediaAsset $asset, Request $request): JsonResponse
    {
        $data = $request->validate([
            'folder_id' => ['nullable', 'string', 'size:26', Rule::exists('media_folders', 'uuid')],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $attributes = array_intersect_key($data, array_flip(['alt_text', 'caption', 'description']));

        if (array_key_exists('folder_id', $data)) {
            $attributes['folder_id'] = $data['folder_id'] === null
                ? null
                : MediaFolder::query()->where('uuid', $data['folder_id'])->value('id');
        }

        $asset->update($attributes);
        $this->audit->log('marketing.media.updated', $asset, null, null, ['filename' => $asset->filename]);

        return ApiResponse::success(['asset' => $this->media->payload($asset->fresh('folder'))]);
    }

    public function replace(MediaAsset $asset, Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:8192']]);
        $usageCount = $asset->usages()->count();
        $asset = $this->media->replace($asset, $request->file('file'), $request->user());

        $this->audit->log('marketing.media.replaced', $asset, null, null, [
            'version' => $asset->version,
            'checksum' => $asset->checksum,
        ]);

        return ApiResponse::success([
            'asset' => $this->media->payload($asset),
            'usage_count' => $usageCount,
            'usage_warning' => $usageCount > 0
                ? 'This asset is used in '.$usageCount.' place(s). The replacement is served immediately everywhere.'
                : null,
        ]);
    }

    public function usages(MediaAsset $asset): JsonResponse
    {
        return ApiResponse::success(['usages' => $this->usagePayloads($asset)]);
    }

    public function versions(MediaAsset $asset): JsonResponse
    {
        return ApiResponse::success([
            'versions' => $asset->versions()->get()->map(fn ($version) => [
                'version' => $version->version,
                'size_bytes' => $version->size_bytes,
                'checksum' => $version->checksum,
                'created_at' => $version->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function destroy(MediaAsset $asset, Request $request): JsonResponse
    {
        $usageCount = $asset->usages()->count();
        $filename = $asset->filename;
        $asset->delete();

        $this->audit->log('marketing.media.deleted', $asset, null, ['filename' => $filename, 'usages' => $usageCount]);

        return ApiResponse::success([
            'deleted' => true,
            'usage_count' => $usageCount,
            'usage_warning' => $usageCount > 0
                ? 'This asset is still referenced in '.$usageCount.' place(s). Replace the reference before relying on it.'
                : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function usagePayloads(MediaAsset $asset): array
    {
        return $asset->usages()->orderByDesc('created_at')->get()->map(fn ($usage) => [
            'id' => $usage->id,
            'usable_type' => class_basename($usage->usable_type),
            'usable_id' => $usage->usable_id,
            'field' => $usage->field,
            'used_at' => $usage->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadRules(): array
    {
        return [
            'file' => ['required', 'file', 'max:8192'],
            'folder_id' => ['nullable', 'string', 'size:26', Rule::exists('media_folders', 'uuid')],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
