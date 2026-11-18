<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\MediaFolder;
use App\Services\Audit\AuditLogger;
use App\Services\Marketing\MediaLibraryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MediaFolderController extends Controller
{
    public function __construct(
        private readonly MediaLibraryService $media,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(['folders' => $this->media->folders()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'parent_id' => ['nullable', 'string', 'size:26', Rule::exists('media_folders', 'uuid')],
        ]);

        $parent = isset($data['parent_id'])
            ? MediaFolder::query()->where('uuid', $data['parent_id'])->firstOrFail()
            : null;

        $folder = $this->media->createFolder($data['name'], $parent, $request->user());
        $this->audit->log('marketing.media_folder.created', $folder, null, null, ['path' => $folder->path]);

        return ApiResponse::success(['folder' => $this->folderPayload($folder)], 201);
    }

    public function update(MediaFolder $folder, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $folder = $this->media->updateFolder($folder, $data['name']);

        $this->audit->log('marketing.media_folder.updated', $folder, null, null, ['path' => $folder->path]);

        return ApiResponse::success(['folder' => $this->folderPayload($folder)]);
    }

    public function destroy(MediaFolder $folder): JsonResponse
    {
        $this->media->deleteFolder($folder);
        $this->audit->log('marketing.media_folder.deleted', $folder, null, ['path' => $folder->path]);

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function folderPayload(MediaFolder $folder): array
    {
        return [
            'id' => $folder->uuid,
            'parent_id' => $folder->parent?->uuid,
            'name' => $folder->name,
            'slug' => $folder->slug,
            'path' => $folder->path,
        ];
    }
}
