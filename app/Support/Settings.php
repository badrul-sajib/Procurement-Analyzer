<?php

namespace App\Support;

use App\Models\SystemSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/** Runtime-editable system settings with defaults. Non-financial, so plain caching is safe. */
final class Settings
{
    public const DEFAULTS = [
        'organization_name' => 'Requisition Analyzer',
        'currency_code' => 'BDT',
        'currency_symbol' => '৳',
        'week_starts_on' => 'saturday',
        'require_po_approval' => true,
        'max_upload_mb' => 10,
        'ocr_enabled' => true,
    ];

    public const RULES = [
        'organization_name' => ['sometimes', 'string', 'max:120'],
        'currency_code' => ['sometimes', 'string', 'size:3'],
        'currency_symbol' => ['sometimes', 'string', 'max:5'],
        'week_starts_on' => ['sometimes', 'in:saturday,sunday,monday'],
        'require_po_approval' => ['sometimes', 'boolean'],
        'max_upload_mb' => ['sometimes', 'integer', 'min:1', 'max:50'],
        'ocr_enabled' => ['sometimes', 'boolean'],
    ];

    private const CACHE_KEY = 'system_settings';

    public static function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => SystemSetting::query()->pluck('value', 'key')->all());

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key];
    }

    public static function put(array $values, ?int $userId): void
    {
        foreach ($values as $key => $value) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    public static function weekStartsOn(): int
    {
        return match (self::get('week_starts_on')) {
            'sunday' => CarbonInterface::SUNDAY,
            'monday' => CarbonInterface::MONDAY,
            default => CarbonInterface::SATURDAY,
        };
    }
}
