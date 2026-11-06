<?php

namespace App\Reports;

use App\Support\DateRange;
use Illuminate\Database\Query\Builder;

/**
 * One definition per report, shared by the API, exports and tests, so an export always matches
 * what the screen showed. Each report filters on its own business event date.
 */
abstract class Report
{
    public const MONEY = 'money';

    public const QTY = 'qty';

    public const INT = 'int';

    public const DATE = 'date';

    public const TEXT = 'text';

    abstract public function title(): string;

    /** Which business date the period filter applies to (shown in the UI and the export header). */
    abstract public function dateBasis(array $filters): string;

    /** @return array<string, array{0: string, 1: string}> column key => [label, type] */
    abstract public function columns(array $filters): array;

    abstract public function query(array $filters, ?DateRange $range): Builder;

    public function permission(): string
    {
        return 'reports.view';
    }

    public function rules(): array
    {
        return [];
    }

    /** Default sort column (must be a column key; '-' for descending). */
    public function defaultSort(): string
    {
        return array_key_first($this->columns([]));
    }

    /** Period grouping expression for date-wise reports (day or month). */
    protected static function bucket(string $column, array $filters): string
    {
        return ($filters['group_by'] ?? 'day') === 'month' ? "DATE_FORMAT({$column}, '%Y-%m')" : "DATE({$column})";
    }

    /** Numeric columns summed over the whole filtered set (not just the page). */
    public function totals(): array
    {
        return [];
    }
}
