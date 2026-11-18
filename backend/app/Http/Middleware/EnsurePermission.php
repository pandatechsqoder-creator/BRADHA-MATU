<?php

namespace App\Http\Middleware;

use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePermission
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $allowed = $user !== null
            && collect(explode('|', $permission))->contains(
                fn (string $slug) => $user->hasPermission($slug, $this->tenantContext->id()),
            );

        if (! $allowed) {
            abort(403, 'You are not allowed to perform this action.');
        }

        return $next($request);
    }
}