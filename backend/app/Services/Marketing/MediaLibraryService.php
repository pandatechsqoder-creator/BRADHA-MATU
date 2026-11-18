<?php

namespace App\Services\Marketing;

use App\Models\Marketing\MediaAsset;
use App\Models\Marketing\MediaAssetVersion;
use App\Models\Marketing\MediaFolder;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MediaLibraryService
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_DIMENSION = 6000;

    private const MAX_PIXELS = 30_000_000;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function folders(): array
    {
        $folders = MediaFolder::query()->with('parent')->withCount('assets')->orderBy('path')->get();

        return $folders->map(fn (MediaFolder $folder) => [
            'id' => $folder->uuid,
            'parent_id' => $folder->parent?->uuid,
            'name' => $folder->name,
            'slug' => $folder->slug,
            'path' => $folder->path,
            'asset_count' => $folder->assets_count,
        ])->all();
    }

    public function createFolder(string $name, ?MediaFolder $parent, User $user): MediaFolder
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            throw ValidationException::withMessages(['name' => 'Provide a folder name.']);
        }

        $path = $parent ? trim($parent->path.'/'.$slug, '/') : $slug;

        if (MediaFolder::query()->where('path', $path)->exists()) {
            throw ValidationException::withMessages(['name' => 'A folder with that name already exists here.']);
        }

        return MediaFolder::create([
            'parent_id' => $parent?->id,
            'name' => $name,
            'slug' => $slug,
            'path' => $path,
            'created_by' => $user->id,
        ]);
    }

    public function updateFolder(MediaFolder $folder, string $name): MediaFolder
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            throw ValidationException::withMessages(['name' => 'Provide a folder name.']);
        }

        if ($slug === $folder->slug) {
            $folder->update(['name' => $name]);

            return $folder;
        }

        $path = $folder->parent ? trim($folder->parent->path.'/'.$slug, '/') : $slug;

        if (MediaFolder::query()->where('path', $path)->whereKeyNot($folder->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'A folder with that name already exists here.']);
        }

        $oldPath = $folder->path;

        return DB::transaction(function () use ($folder, $name, $slug, $path, $oldPath): MediaFolder {
            $folder->update(['name' => $name, 'slug' => $slug, 'path' => $path]);

            foreach (MediaFolder::query()->where('path', 'like', $oldPath.'/%')->get() as $child) {
                $child->update(['path' => $path.substr($child->path, strlen($oldPath))]);
            }

            return $folder->fresh();
        });
    }

    public function deleteFolder(MediaFolder $folder): void
    {
        if ($folder->assets()->exists() || $folder->children()->exists()) {
            throw ValidationException::withMessages([
                'folder' => 'Move the media and child folders out before deleting this folder.',
            ]);
        }

        $folder->delete();
    }

    /**
     * @param  array{search?: ?string, folder?: ?string, mime?: ?string}  $filters
     * @return LengthAwarePaginator<int, MediaAsset>
     */
    public function assets(array $filters = []): LengthAwarePaginator
    {
        $query = MediaAsset::query()->with('folder')->orderByDesc('created_at');

        if (! empty($filters['folder'])) {
            $folder = MediaFolder::query()->where('uuid', $filters['folder'])->firstOrFail();
            $query->where('folder_id', $folder->id);
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($where) => $where
                ->where('filename', 'like', $search)
                ->orWhere('alt_text', 'like', $search));
        }

        if (! empty($filters['mime'])) {
            $query->where('mime', 'like', $filters['mime'].'%');
        }

        return $query->paginate(24)->withQueryString();
    }

    /**
     * @param  array{folder_id?: ?string, alt_text?: ?string, caption?: ?string, description?: ?string}  $data
     */
    public function upload(UploadedFile $file, array $data, User $user): MediaAsset
    {
        $processed = $this->process($file);
        $folder = $this->folder($data['folder_id'] ?? null);
        $path = ($folder?->path ?: 'library').'/'.Str::ulid().'.'.$processed['extension'];

        Storage::disk('local')->put($path, $processed['contents']);

        $existing = MediaAsset::query()->where('checksum', $processed['checksum'])->first();

        if ($existing !== null) {
            Storage::disk('local')->delete($path);

            return $existing;
        }

        return DB::transaction(function () use ($file, $data, $user, $folder, $path, $processed): MediaAsset {
            $asset = MediaAsset::create([
                'folder_id' => $folder?->id,
                'disk' => 'local',
                'path' => $path,
                'filename' => $this->safeFilename($file, $processed['extension']),
                'mime' => $processed['mime'],
                'extension' => $processed['extension'],
                'size_bytes' => strlen($processed['contents']),
                'width' => $processed['width'],
                'height' => $processed['height'],
                'checksum' => $processed['checksum'],
                'version' => 1,
                'alt_text' => $data['alt_text'] ?? null,
                'caption' => $data['caption'] ?? null,
                'description' => $data['description'] ?? null,
                'uploaded_by' => $user->id,
            ]);

            MediaAssetVersion::create([
                'media_asset_id' => $asset->id,
                'version' => 1,
                'path' => $path,
                'size_bytes' => strlen($processed['contents']),
                'checksum' => $processed['checksum'],
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            return $asset;
        });
    }

    public function replace(MediaAsset $asset, UploadedFile $file, User $user): MediaAsset
    {
        $processed = $this->process($file);

        if ($processed['checksum'] === $asset->checksum) {
            throw ValidationException::withMessages(['file' => 'This file is identical to the current version.']);
        }

        $folderPath = $asset->folder?->path ?: 'library';
        $path = $folderPath.'/'.Str::ulid().'.'.$processed['extension'];

        Storage::disk('local')->put($path, $processed['contents']);

        return DB::transaction(function () use ($asset, $file, $user, $path, $processed): MediaAsset {
            $version = (int) $asset->version + 1;

            $asset->update([
                'path' => $path,
                'filename' => $this->safeFilename($file, $processed['extension']),
                'mime' => $processed['mime'],
                'extension' => $processed['extension'],
                'size_bytes' => strlen($processed['contents']),
                'width' => $processed['width'],
                'height' => $processed['height'],
                'checksum' => $processed['checksum'],
                'version' => $version,
            ]);

            MediaAssetVersion::create([
                'media_asset_id' => $asset->id,
                'version' => $version,
                'path' => $path,
                'size_bytes' => strlen($processed['contents']),
                'checksum' => $processed['checksum'],
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            return $asset->fresh(['folder', 'versions']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(MediaAsset $asset): array
    {
        return [
            'id' => $asset->uuid,
            'folder_id' => $asset->folder?->uuid,
            'folder_path' => $asset->folder?->path,
            'filename' => $asset->filename,
            'mime' => $asset->mime,
            'extension' => $asset->extension,
            'size_bytes' => $asset->size_bytes,
            'width' => $asset->width,
            'height' => $asset->height,
            'version' => $asset->version,
            'alt_text' => $asset->alt_text,
            'caption' => $asset->caption,
            'description' => $asset->description,
            'url' => $asset->publicUrl(),
            'usage_count' => $asset->usages()->count(),
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }

    private function folder(?string $uuid): ?MediaFolder
    {
        return $uuid === null ? null : MediaFolder::query()->where('uuid', $uuid)->firstOrFail();
    }

    /**
     * @return array{contents: string, mime: string, extension: string, checksum: string, width: ?int, height: ?int}
     */
    private function process(UploadedFile $file): array
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'The file is larger than the 8 MB limit.']);
        }

        $mime = (string) $file->getMimeType();
        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The upload could not be read.']);
        }

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
        ];

        if (! isset($allowed[$mime])) {
            throw ValidationException::withMessages(['file' => 'Upload a JPEG, PNG, WebP, GIF or PDF file.']);
        }

        if ($mime === 'application/pdf') {
            $contents = (string) file_get_contents($path);

            return [
                'contents' => $contents,
                'mime' => $mime,
                'extension' => 'pdf',
                'checksum' => hash('sha256', $contents),
                'width' => null,
                'height' => null,
            ];
        }

        return $this->processImage($path, $mime);
    }

    /**
     * Re-encode raster images to strip metadata and normalise the file.
     *
     * @return array{contents: string, mime: string, extension: string, checksum: string, width: ?int, height: ?int}
     */
    private function processImage(string $source, string $mime): array
    {
        $size = @getimagesize($source);

        if ($size === false) {
            throw ValidationException::withMessages(['file' => 'The image could not be read.']);
        }

        [$width, $height] = $size;

        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > self::MAX_PIXELS) {
            throw ValidationException::withMessages(['file' => 'The image dimensions are too large (max 6000px and 30 megapixels).']);
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => @imagecreatefromwebp($source),
            'image/gif' => @imagecreatefromgif($source),
            default => false,
        };

        if ($image === false) {
            throw ValidationException::withMessages(['file' => 'The image could not be processed.']);
        }

        if ($mime === 'image/jpeg') {
            $image = $this->orient($image, $source);
        }

        $temp = tempnam(sys_get_temp_dir(), 'media');

        if ($temp === false) {
            imagedestroy($image);
            throw ValidationException::withMessages(['file' => 'The image could not be processed.']);
        }

        try {
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($image, $temp, 82),
                'image/png' => imagepng($image, $temp, 6),
                'image/webp' => imagewebp($image, $temp, 82),
                'image/gif' => imagegif($image, $temp),
                default => false,
            };

            if (! $ok) {
                throw ValidationException::withMessages(['file' => 'The image could not be processed.']);
            }

            $contents = (string) file_get_contents($temp);
        } finally {
            imagedestroy($image);
            @unlink($temp);
        }

        return [
            'contents' => $contents,
            'mime' => $mime,
            'extension' => match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            },
            'checksum' => hash('sha256', $contents),
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function orient($image, string $source)
    {
        $orientation = @exif_read_data($source)['Orientation'] ?? 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated === null) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function safeFilename(UploadedFile $file, string $extension): string
    {
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        return ($name === '' ? 'asset' : $name).'.'.$extension;
    }
}
