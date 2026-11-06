<?php

namespace App\Exports;

use App\Reports\Report;
use App\Reports\ReportRunner;
use Illuminate\Database\Query\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/** Spreadsheet/CSV for any report. Streams the query in chunks rather than loading it at once. */
final class ReportSheet extends DefaultValueBinder implements FromQuery, ShouldAutoSize, WithCustomValueBinder, WithHeadings, WithMapping, WithTitle
{
    public int $rows = 0;

    public function __construct(private ReportRunner $runner, private bool $numericCells) {}

    public function query(): Builder
    {
        // Laravel Excel chunks by offset; a stable order comes from ReportRunner::rows().
        return $this->runner->rows();
    }

    public function headings(): array
    {
        return array_map(fn ($c) => $c[0], $this->runner->report->columns($this->runner->filters));
    }

    public function map($row): array
    {
        $this->rows++;
        $types = $this->runner->report->columns($this->runner->filters);

        return collect($this->runner->shape($row))->map(function ($value, $key) use ($types) {
            if ($this->numericCells && in_array($types[$key][1], [Report::MONEY, Report::QTY, Report::INT], true) && $value !== null) {
                return (float) $value; // a real number cell in Excel; the exact decimal is kept in CSV
            }

            return self::safe($value);
        })->values()->all();
    }

    /** CSV keeps exact decimal text ("10.000"); XLSX gets real number cells for numeric columns. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (! $this->numericCells && $value !== null) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function title(): string
    {
        return mb_substr($this->runner->report->title(), 0, 31);
    }

    /** Spreadsheet formula injection: text starting with = + - @ (or tab/CR) is forced to plain text. */
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
