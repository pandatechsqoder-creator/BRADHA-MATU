<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CanonicalHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->has('tenant_host_id')) {
            return $next($request);
        }

        $base = (string) (config('marketing.canonical_base') ?: '');

        if ($base === '' || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $target = parse_url($base);

        if (! isset($target['host']) || strcasecmp($request->getHost(), $target['host']) === 0) {
            return $next($request);
        }

        $scheme = $target['scheme'] ?? 'https';
        $port = isset($target['port']) ? ':'.$target['port'] : '';

        return redirect()->away($scheme.'://'.$target['host'].$port.$request->getRequestUri(), 301);
    }
}
