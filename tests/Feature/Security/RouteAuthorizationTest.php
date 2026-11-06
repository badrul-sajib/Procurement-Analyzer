<?php

use Illuminate\Support\Facades\Route;

/*
| Guards against a new API route shipping without authorization. Every /api/v1 route must be
| authenticated and declare a permission, unless it is listed here with a reason.
*/
const PUBLIC_ROUTES = ['auth.login', 'auth.tokens.store'];
const AUTH_ONLY_ROUTES = [
    'auth.me', 'auth.logout', 'auth.tokens.destroy', // the caller's own session/token
    'settings.show',                                 // non-secret UI settings (currency, week start)
    'exports.show', 'exports.download',              // owner-checked in ReportExportPolicy
];

it('protects every api route with authentication and a permission', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'));
    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $name = $route->getName();
        expect($name)->not->toBeNull("Route {$route->uri()} must be named.");
        if (in_array($name, PUBLIC_ROUTES, true)) {
            continue;
        }
        $middleware = $route->gatherMiddleware();
        expect(in_array('auth:sanctum', $middleware, true))->toBeTrue("{$name} is not authenticated")
            ->and(in_array('active', $middleware, true))->toBeTrue("{$name} does not reject deactivated users");

        if (! in_array($name, AUTH_ONLY_ROUTES, true)) {
            $hasPermission = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));
            expect($hasPermission)->toBeTrue("{$name} declares no permission middleware");
        }
    }
});
