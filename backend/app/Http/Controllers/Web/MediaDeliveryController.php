<?php

namespace App\Http\Controllers\Web;

use App\Models\Marketing\MediaAsset;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaDeliveryController extends \App\Http\Controllers\Controller
{
    public function __invoke(string $uuid): StreamedResponse
    {
        $asset = MediaAsset::query()->where('uuid', $uuid)->firstOrFail();
        $disk = Storage::disk($asset->disk);

        abort_unless($disk->exists($asset->path), 404);

        return $disk->response($asset->path, $asset->filename, [
            'Content-Type' => $asset->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
