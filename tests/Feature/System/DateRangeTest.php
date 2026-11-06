<?php

use App\Support\DateRange;
use Carbon\CarbonImmutable;

// 2026-11-05 20:30 UTC is already 2026-11-06 02:30 in Dhaka (UTC+6): presets must use the Dhaka day.
$now = CarbonImmutable::parse('2026-11-05 20:30:00', 'UTC');

it('resolves presets in the business time zone', function (string $period, string $from, string $to) use ($now) {
    $range = DateRange::resolve($period, now: $now);
    expect($range->dates())->toBe([$from, $to]);
})->with([
    'today' => ['today', '2026-11-06', '2026-11-06'],
    'yesterday' => ['yesterday', '2026-11-05', '2026-11-05'],
    'this week (Sat start)' => ['this_week', '2026-10-31', '2026-11-06'],
    'last week' => ['last_week', '2026-10-24', '2026-10-30'],
    'this month' => ['this_month', '2026-11-01', '2026-11-30'],
    'last month' => ['last_month', '2026-10-01', '2026-10-31'],
    'this year' => ['this_year', '2026-01-01', '2026-12-31'],
]);

it('converts a Dhaka day to UTC bounds for timestamp columns', function () {
    $range = DateRange::resolve('custom', '2026-11-06', '2026-11-06');
    expect($range->utcBounds())->toBe(['2026-11-05 18:00:00', '2026-11-06 17:59:59']);
});
