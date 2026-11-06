<?php

namespace App\Providers;

use App\DocumentProcessing\Ocr\NullOcrEngine;
use App\DocumentProcessing\Ocr\OcrEngine;
use App\DocumentProcessing\Ocr\TesseractOcrEngine;
use App\Support\PermissionCatalog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OcrEngine::class, fn () => match (config('services.ocr.driver')) {
            'tesseract' => new TesseractOcrEngine(config('services.ocr')),
            default => new NullOcrEngine,
        });
    }

    public function boot(): void
    {
        // Outside production: lazy loading throws (N+1 guard) and so do unfillable attributes (mass-assignment guard).
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Context::add('request_id', (string) Str::uuid());

        Gate::before(fn ($user) => $user->hasRole(PermissionCatalog::SUPER_ADMIN) ? true : null);
        // /docs/api (Scramble): open locally, administrators only elsewhere.
        Gate::define('viewApiDocs', fn ($user = null) => $this->app->environment('local') || $user?->can('users.manage'));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        $threshold = (int) env('SLOW_QUERY_MS', 500);
        DB::listen(function (QueryExecuted $query) use ($threshold) {
            if ($query->time >= $threshold) {
                Log::channel('slow-queries')->warning('Slow query', [
                    'ms' => $query->time, 'sql' => $query->sql, 'connection' => $query->connectionName,
                ]);
            }
        });
    }
}
