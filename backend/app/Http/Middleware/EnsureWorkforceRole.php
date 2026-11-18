<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Worker self-service endpoints must be reachable only by users holding a
 * workforce-scoped role, never by tenant or platform operators.
 */
final class EnsureWorkforceRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $isWorkforce = $user && $user->roles()
            ->where('roles.scope', 'workforce')
            ->whereNull('user_roles.tenant_id')
            ->exists();

        if (! $isWorkforce) {
            abort(403, 'You are not allowed to access the workforce workspace.');
        }

        return $next($request);
    }
}
