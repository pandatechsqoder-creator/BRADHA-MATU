<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicIndexingHeaders
{
    private const PRIVATE_PREFIXES = [
        'api/',
        'app',
        'business',
        'pos',
        'platform',
        'workforce',
        'login',
        'register',
        'forgot-password',
        'verify',
        'onboarding',
        'profile',
        'settings',
        'notifications',
        'support',
        'invitations',
        'preview/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $path = ltrim($request->path(), '/');

        $private = collect(self::PRIVATE_PREFIXES)->contains(function (string $prefix) use ($path): bool {
            $root = rtrim($prefix, '/');

            return $path === $root || str_starts_with($path, $root.'/');
        });

        if ($private || $response->isNotFound() || $response->getStatusCode() === 410) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
