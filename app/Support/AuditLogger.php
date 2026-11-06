<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Arr;

final class AuditLogger
{
    private const MASKED = ['password', 'password_confirmation', 'remember_token', 'token', 'current_password'];

    public static function log(
        string $action,
        string $module,
        ?int $recordId = null,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
    ): ActivityLog {
        $request = request();

        return ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId,
            'description' => $description,
            'old_values' => self::mask($old),
            'new_values' => self::mask($new),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    /** Only the changed keys of an Eloquent update: [old, new]. */
    public static function diff(array $before, array $after): array
    {
        $changed = array_keys(array_diff_assoc(
            array_map(fn ($v) => json_encode($v), $after),
            array_map(fn ($v) => json_encode($v), Arr::only($before, array_keys($after))),
        ));

        return [Arr::only($before, $changed), Arr::only($after, $changed)];
    }

    private static function mask(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach (self::MASKED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '***';
            }
        }

        return $values;
    }
}
