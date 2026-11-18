<?php

namespace App\Http\Middleware;

use App\Models\TenantDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $baseDomain = strtolower(trim((string) config('tenancy.base_domain'), '.'));
        $host = strtolower($request->getHost());

        if ($baseDomain === '' || $host === $baseDomain || ! str_ends_with($host, '.'.$baseDomain)) {
            return $next($request);
        }

        $subdomain = substr($host, 0, -strlen('.'.$baseDomain));
        if ($subdomain === '' || str_contains($subdomain, '.')) {
            abort(404);
        }

        if (in_array($subdomain, config('tenancy.platform_subdomains', []), true)) {
            return $next($request);
        }

        $domain = TenantDomain::query()
            ->where('subdomain', $subdomain)
            ->where('status', 'allocated')
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->first();

        if ($domain === null) {
            abort(404);
        }

        $request->attributes->set('tenant_host_id', $domain->tenant_id);

        return $next($request);
    }
}
