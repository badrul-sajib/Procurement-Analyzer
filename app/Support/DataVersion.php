<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cache invalidation for summaries: every write bumps a version after commit, and cached
 * summaries are keyed by it, so a cached figure can never outlive the data it came from.
 */
final class DataVersion
{
    private const KEY = 'data_version';

    public static function current(): int
    {
        return (int) Cache::get(self::KEY, 0);
    }

    public static function bump(): void
    {
        DB::afterCommit(fn () => Cache::increment(self::KEY));
    }

    public static function remember(string $key, array $params, \Closure $callback): mixed
    {
        ksort($params);
        $cacheKey = $key.':v'.self::current().':'.md5(json_encode($params));

        return Cache::remember($cacheKey, now()->addMinutes(10), $callback);
    }
}
