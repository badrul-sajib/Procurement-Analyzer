<?php

namespace App\DocumentProcessing\Extractors;

use App\DocumentProcessing\ExtractedLine;
use App\DocumentProcessing\ExtractionResult;
use App\DocumentProcessing\LineParser;
use App\DocumentProcessing\TextNormalizer;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Row;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * First worksheet only, read in chunks. Formulas are never evaluated: a formula cell yields
 * the value Excel cached when the file was saved.
 */
final class SpreadsheetImport implements OnEachRow, WithChunkReading, WithMultipleSheets
{
    public const MAX_LINES = 2000;

    private ?array $columns = null;

    /** Rows seen before a header row was found. */
    private array $pending = [];

    public function __construct(private ExtractionResult $result, private LineParser $parser) {}

    public function sheets(): array
    {
        return [0 => $this];
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function onRow(Row $row): void
    {
        if (count($this->result->lines) >= self::MAX_LINES) {
            return;
        }
        $cells = [];
        foreach ($row->getDelegate()->getCellIterator() as $cell) {
            $value = $cell->getValue();
            if ($value instanceof RichText) {
                $value = $value->getPlainText();
            } elseif ($cell->isFormula()) {
                $value = $cell->getOldCalculatedValue();
            }
            if ($value !== null && ExcelDate::isDateTime($cell) && is_numeric($value)) {
                $value = ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }
            $cells[] = is_string($value) ? TextNormalizer::clean($value) : $value;
        }
        $filled = array_values(array_filter($cells, fn ($c) => $c !== null && $c !== ''));
        if (! $filled) {
            return;
        }

        if ($this->columns === null) {
            // Look for a header row among the first 30 non-empty rows; rows above it are document headers.
            if ($map = LineParser::headerMap($cells)) {
                $this->columns = $map;
                array_walk($this->pending, fn ($r) => $this->mergeHeader($r));
                $this->pending = [];

                return;
            }
            if (count($this->pending) < 30) {
                $this->pending[] = $filled;

                return;
            }
            // No header row: replay what we held back as positional item rows.
            $this->columns = [];
            foreach ($this->pending as $pendingRow) {
                $this->addPositional($pendingRow);
            }
            $this->pending = [];
        }

        $this->columns ? $this->addLine($this->parser->fromColumns($cells, $this->columns), $filled) : $this->addPositional($filled);
    }

    /** Called by Laravel Excel after the last chunk is read (see finish()). */
    public function finish(): void
    {
        if ($this->columns === null) {
            foreach ($this->pending as $pendingRow) {
                $this->addPositional($pendingRow);
            }
        }
    }

    private function addPositional(array $filled): void
    {
        $text = implode("\t", array_map('strval', $filled));
        $parsed = $this->parser->parse($text, 'excel');
        foreach (['externalReference', 'requisitionDate', 'department', 'title'] as $field) {
            $this->result->{$field} ??= $parsed->{$field};
        }
        $this->addLine($parsed->lines[0] ?? null, $filled, $parsed->skipped === [] && $parsed->lines === []);
    }

    private function addLine(?ExtractedLine $line, array $filled, bool $silent = false): void
    {
        if ($line && $line->name !== '') {
            $this->result->lines[] = $line;
        } elseif (! $silent) {
            $this->result->skip(implode(' | ', array_map('strval', $filled)));
        }
    }

    /** Key/value rows above the table, e.g. "Requisition No:" | "R-1029". */
    private function mergeHeader(array $filled): void
    {
        $text = count($filled) === 2 ? "{$filled[0]}: {$filled[1]}" : implode(' ', array_map('strval', $filled));
        $header = $this->parser->parse(str_replace('::', ':', $text), 'excel');
        foreach (['externalReference', 'requisitionDate', 'department', 'title'] as $field) {
            $this->result->{$field} ??= $header->{$field};
        }
    }
}
