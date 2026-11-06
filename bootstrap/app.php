<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\Idempotency;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Context;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA requests from SANCTUM_STATEFUL_DOMAINS get session-cookie auth + CSRF on /api.
        $middleware->statefulApi();
        $middleware->throttleApi('api');
        // Check permissions before route-model binding, so unauthorized callers get 403 whether or not
        // the record exists (no probing for valid ids).
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserIsActive::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, PermissionMiddleware::class);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'idempotent' => Idempotency::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Never leak internals: production 5xx responses carry only a correlation id found in the logs.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() >= 500 && ! config('app.debug') && $request->is('api/*')) {
                return response()->json(['message' => 'Server error', 'reference' => Context::get('request_id')], $response->getStatusCode());
            }

            return $response;
        });
    })->create();
