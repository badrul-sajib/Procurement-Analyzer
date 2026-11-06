<?php

namespace App\Reports;

use App\Support\DateRange;
use App\Support\Decimal;
use App\Support\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Validates filters, applies sorting, and shapes rows for any Report. */
final class ReportRunner
{
    public function __construct(public readonly Report $report, public readonly array $filters, public readonly ?DateRange $range) {}

    /** Filters come from a request (GET) or from a stored export (array). */
    public static function make(Report $report, array $input): self
    {
        $filters = Validator::make($input, DateRange::rules() + $report->rules() + ['sort' => ['nullable', 'string']])->validate();
        $range = ! empty($filters['period']) || ! empty($filters['date_from'])
            ? DateRange::resolve($filters['period'] ?? 'custom', $filters['date_from'] ?? null, $filters['date_to'] ?? null)
            : null;

        return new self($report, $filters, $range);
    }

    /** Wrapped so every column alias is sortable without ambiguity. */
    public function rows(): Builder
    {
        $sort = $this->filters['sort'] ?? $this->report->defaultSort();
        $key = ltrim($sort, '-');
        $columns = $this->report->columns($this->filters);
        if (! array_key_exists($key, $columns)) {
            throw ValidationException::withMessages(['sort' => 'Allowed sorts: '.implode(', ', array_keys($columns))]);
        }

        return DB::query()->fromSub($this->report->query($this->filters, $this->range), 'rep')
            ->select('rep.*')
            ->orderBy("rep.{$key}", str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByRaw('1');
    }

    public function totals(): array
    {
        $sums = $this->report->totals();
        if (! $sums) {
            return [];
        }
        $row = DB::query()->fromSub($this->report->query($this->filters, $this->range), 'rep')
            ->selectRaw(implode(', ', array_map(fn ($c) => "COALESCE(SUM(rep.`{$c}`), 0) AS `{$c}`", $sums)))->first();

        return collect($sums)->mapWithKeys(fn ($c) => [$c => $this->format($c, $row->{$c})])->all();
    }

    public function shape(object $row): array
    {
        return collect($this->report->columns($this->filters))->mapWithKeys(fn ($def, $key) => [$key => $this->format($key, $row->{$key} ?? null)])->all();
    }

    public function format(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $type = $this->report->columns($this->filters)[$key][1] ?? Report::TEXT;

        return match ($type) {
            Report::MONEY => Decimal::add((string) $value, 0, Decimal::MONEY),
            Report::QTY => Decimal::add((string) $value, 0),
            Report::INT => (int) $value,
            Report::DATE => substr((string) $value, 0, 10),
            default => $key === 'uploaded_at' ? CarbonImmutable::parse($value, 'UTC')->setTimezone(config('app.business_timezone'))->format('Y-m-d H:i') : $value,
        };
    }

    public function describe(): array
    {
        return [
            'title' => $this->report->title(),
            'date_basis' => $this->report->dateBasis($this->filters),
            'range' => $this->range?->toArray(),
            'columns' => collect($this->report->columns($this->filters))->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0], 'type' => $c[1]])->values(),
        ];
    }

    public function page(Request $request): array
    {
        $page = $this->rows()->paginate(ListQuery::perPage($request))->withQueryString()->through(fn ($r) => $this->shape($r));

        return ListQuery::envelope($page) + ['report' => $this->describe(), 'totals' => $this->totals()];
    }
}
