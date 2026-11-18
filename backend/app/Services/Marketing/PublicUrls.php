<?php

namespace App\Services\Marketing;

final class PublicUrls
{
    public static function base(): string
    {
        return rtrim((string) (config('marketing.canonical_base') ?: config('app.url')), '/');
    }

    public static function path(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? self::base().'/' : self::base().'/'.$path;
    }
}
