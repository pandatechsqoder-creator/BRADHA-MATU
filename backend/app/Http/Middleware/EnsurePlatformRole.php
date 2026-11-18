<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform endpoints must be reachable only by users holding a platform-scoped
 * role (user_roles.tenant_id IS NULL), never by tenant-scoped permissions.
 */
final class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $isPlatform = $user && $user->roles()
            ->where('roles.scope', 'platform')
            ->whereNull('user_roles.tenant_id')
            ->exists();

        if (! $isPlatform) {
            abort(403, 'You are not allowed to access platform administration.');
        }

        return $next($request);
    }
}
