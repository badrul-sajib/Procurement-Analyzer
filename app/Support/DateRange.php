<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A closed calendar-date range in the business time zone (Asia/Dhaka by default).
 * Business DATE columns compare against dates(); timestamp columns against utcBounds().
 */
final class DateRange
{
    public const PERIODS = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'custom'];

    public function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to) {}

    /** Validation rules to merge into a Form Request. */
    public static function rules(): array
    {
        return [
            'period' => ['nullable', 'in:'.implode(',', self::PERIODS)],
            'date_from' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    /** Null when no period and no dates were given (= all time). */
    public static function fromRequest(Request $request): ?self
    {
        $period = $request->query('period') ?? $request->input('period');
        $from = $request->query('date_from') ?? $request->input('date_from');
        $to = $request->query('date_to') ?? $request->input('date_to');

        if (! $period && ($from || $to)) {
            $period = 'custom';
        }

        return $period ? self::resolve($period, $from, $to) : null;
    }

    public static function resolve(string $period, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): self
    {
        $tz = config('app.business_timezone');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        $weekStart = Settings::weekStartsOn();

        return match ($period) {
            'today' => new self($now->startOfDay(), $now->endOfDay()),
            'yesterday' => new self($now->subDay()->startOfDay(), $now->subDay()->endOfDay()),
            'this_week' => new self($s = $now->startOfWeek($weekStart), $s->addDays(6)->endOfDay()),
            'last_week' => new self($s = $now->startOfWeek($weekStart)->subWeek(), $s->addDays(6)->endOfDay()),
            'this_month' => new self($now->startOfMonth(), $now->endOfMonth()),
            'last_month' => new self($now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()),
            'this_year' => new self($now->startOfYear(), $now->endOfYear()),
            'custom' => new self(
                CarbonImmutable::createFromFormat('Y-m-d', $from ?? $to, $tz)->startOfDay(),
                CarbonImmutable::createFromFormat('Y-m-d', $to ?? $from, $tz)->endOfDay(),
            ),
            default => throw ValidationException::withMessages(['period' => 'Unknown period.']),
        };
    }

    /** @return array{0: string, 1: string} Y-m-d bounds for DATE columns. */
    public function dates(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    /** @return array{0: string, 1: string} UTC datetime bounds for TIMESTAMP columns. */
    public function utcBounds(): array
    {
        return [
            $this->from->utc()->format('Y-m-d H:i:s'),
            $this->to->utc()->format('Y-m-d H:i:s'),
        ];
    }

    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
