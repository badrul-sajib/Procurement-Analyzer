<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replays the first response for a repeated Idempotency-Key (24h), so a retried
 * payment or PO submission is never applied twice. Header is optional.
 */
class Idempotency
{
    private const TTL = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return $next($request);
        }
        if (strlen($key) > 100) {
            return response()->json(['message' => 'Idempotency-Key is too long.', 'code' => 'invalid_idempotency_key'], 422);
        }

        $cacheKey = 'idempotency:'.$request->user()?->id.':'.$key;
        $fingerprint = hash('sha256', $request->method().$request->path().json_encode($request->all()));

        if ($stored = Cache::get($cacheKey)) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'A request with this Idempotency-Key is in progress.', 'code' => 'idempotency_in_progress'], 409);
        }

        try {
            if ($stored = Cache::get($cacheKey)) {
                return $this->replay($stored, $fingerprint);
            }
            $response = $next($request);
            // Only remember outcomes; a 5xx may be retried with the same key.
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                ], self::TTL);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(array $stored, string $fingerprint): Response
    {
        if ($stored['fingerprint'] !== $fingerprint) {
            return response()->json(['message' => 'Idempotency-Key was already used with a different request.', 'code' => 'idempotency_mismatch'], 409);
        }

        return response($stored['body'], $stored['status'], ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }
}
