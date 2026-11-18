<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CmsBanner;
use Illuminate\Database\QueryException;

final class PublicBanner
{
    /**
     * @return array<string, mixed>|null
     */
    public function active(): ?array
    {
        try {
            $banner = CmsBanner::query()
                ->with('asset')
                ->active()
                ->where('key', 'site')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->first();
        } catch (QueryException) {
            return null;
        }

        if ($banner === null) {
            return null;
        }

        return [
            'title' => $banner->title,
            'body' => $banner->body,
            'url' => $banner->link_url,
            'image_url' => $banner->asset?->publicUrl(),
        ];
    }
}
