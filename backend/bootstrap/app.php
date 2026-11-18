<?php

use App\Http\Middleware\CanonicalHost;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\EnsureWorkforceRole;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\PublicIndexingHeaders;
use App\Http\Middleware\ResolveTenantHost;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->web(prepend: [ResolveTenantHost::class, CanonicalHost::class]);
        $middleware->web(append: [PublicIndexingHeaders::class]);
        $middleware->api(prepend: [ForceJsonResponse::class, ResolveTenantHost::class]);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'password.changed' => EnsurePasswordChanged::class,
            'permission' => EnsurePermission::class,
            'platform' => EnsurePlatformRole::class,
            'workforce' => EnsureWorkforceRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('VALIDATION_FAILED', 'The supplied data is invalid.', 422, $e->errors());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('UNAUTHENTICATED', 'Authentication is required.', 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('FORBIDDEN', 'You are not allowed to perform this action.', 403);
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', 404);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $code = match ($e->getStatusCode()) {
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    429 => 'RATE_LIMITED',
                    default => 'HTTP_ERROR',
                };

                return ApiResponse::error(
                    $code,
                    $e->getMessage() ?: 'Request failed.',
                    $e->getStatusCode(),
                );
            }
        });
    })->create();
