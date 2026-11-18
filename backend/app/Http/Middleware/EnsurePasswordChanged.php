<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! (bool) $user->must_change_password) {
            return $next($request);
        }

        $path = $request->path();
        if (
            str_ends_with($path, 'auth/change-initial-password')
            || str_ends_with($path, 'auth/logout')
            || str_ends_with($path, 'me')
        ) {
            return $next($request);
        }

        abort(403, 'You must create a new password before continuing.');
    }
}
