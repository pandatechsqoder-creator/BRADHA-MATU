<?php

namespace App\Security;

use Illuminate\Foundation\Http\Middleware\Middleware;

class SecurityHeadersMiddleware extends Middleware
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);

        // Content Security Policy
        $response->headers->set('Content-Security-Policy', "default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; script-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; img-src 'self' https: data:; connect-src 'self' https:; style-src 'self' https: 'unsafe-inline'; font-src 'self' https: data:");

        // X-Content-Type-Options
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // X-Frame-Options
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // X-XSS-Protection
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Strict-Transport-Security
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

        // Referrer-Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions-Policy
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        return $response;
    }
}