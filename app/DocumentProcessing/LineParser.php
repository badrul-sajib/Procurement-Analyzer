<?php

namespace App\DocumentProcessing;

/**
 * Turns free text (TXT files, PDF text layers, OCR output) into header fields and item lines.
 * Heuristic by nature: anything it cannot read confidently is reported as skipped, and every
 * result goes through human review before it can be verified.
 */
final class LineParser
{
    private const NUM = '(?<qty>\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)';

    private const HEADER_FIELDS = [
        'externalReference' => '(?:requisition|req|reference|ref|indent|memo)\.?\s*(?:no|number|#|id)?\.?',
        'requisitionDate' => '(?:requisition\s+)?date(?:\s+of\s+requisition)?',
        'department' => '(?:department|dept|branch|destination|section|office|from)',
        'title' => '(?:subject|title|purpose)',
    ];

    /** Lines that look like "word number" but are never items. */
    private const NOISE = ['total', 'grand total', 'sub total', 'subtotal', 'page', 'sl', 'sl no', 'serial', 'signature',
        'approved by', 'prepared by', 'checked by', 'requested by', 'received by', 'authorized by', 'phone', 'mobile', 'tel'];

    /** Column header words for delimited / table-like text. */
    public const COLUMNS = [
        'serial' => ['sl', 'sl no', 's l', 's no', 'sn', 'sr', 'sr no', 'serial', 'serial no', 'no'],
        'name' => ['item', 'items', 'item name', 'name', 'particulars', 'product', 'product name', 'description of goods', 'item description', 'goods', 'article'],
        'quantity' => ['qty', 'quantity', 'req qty', 'required qty', 'requested qty', 'requested quantity', 'required quantity', 'qnty', 'qty required'],
        'unit' => ['unit', 'uom', 'unit of measurement', 'unit of measure', 'measure', 'units'],
        'description' => ['description', 'specification', 'spec', 'details', 'size', 'model'],
        'department' => ['department', 'dept', 'branch', 'destination', 'section', 'deliver to'],
        'requested_date' => ['date', 'required date', 'requested date', 'need by', 'required by', 'delivery date'],
        'notes' => ['remarks', 'remark', 'notes', 'note', 'comment', 'comments', 'purpose', 'reason'],
    ];

    /** @param  \Closure(string): bool  $isUnit */
    public function __construct(private \Closure $isUnit) {}

    public function parse(string $text, string $extractor): ExtractionResult
    {
        $result = new ExtractionResult($extractor);
        $rows = preg_split('/\R/u', $text);

        foreach ($rows as $at => $raw) {
            $cells = $this->cells(TextNormalizer::clean($raw));
            if (count($cells) >= 2 && self::headerMap($cells)) {
                $this->parseTable($rows, $at, $result);

                return $result;
            }
        }

        foreach ($rows as $raw) {
            $line = TextNormalizer::clean($raw);
            if ($line === '' || ! preg_match('/\p{L}/u', $line) || $this->parseHeader($line, $result)) {
                continue;
            }
            $cells = $this->cells($line);
            $parsed = count($cells) >= 2 ? $this->fromCells($cells) : $this->fromSentence($line);
            $this->add($parsed, $line, $result);
        }

        return $result;
    }

    /**
     * A document with an item table: only rows of the table become lines; text around it is read
     * for header fields only. When the table has a serial column, a row with neither a serial number
     * nor a quantity is the wrapped part of the row above ("6A SP Circuit" / "Braker(ACI)").
     */
    private function parseTable(array $rows, int $at, ExtractionResult $result): void
    {
        foreach (array_slice($rows, 0, $at) as $raw) {
            $line = TextNormalizer::clean($raw);
            if ($line === '' || $this->parseHeader($line, $result)) {
                continue;
            }
            // "Hub Name: X   Date :05/11/2026": labels can share a line.
            $found = array_filter($this->cells($line), fn ($cell) => $this->parseHeader($cell, $result));
            if (! $found && preg_match('/\p{L}/u', $line)) {
                $result->skip($line);
            }
        }

        $table = null;
        $previous = null; // the line a wrapped row continues
        foreach (array_slice($rows, $at) as $raw) {
            $line = TextNormalizer::clean($raw);
            if ($line === '' || ! preg_match('/\p{L}/u', $line)) {
                $previous = null;

                continue;
            }
            if (($cells = $this->cells($line)) && count($cells) >= 2 && ($map = self::headerMap($cells))) {
                // The header (again on each page): columns by index, and by position for layout text.
                $table = ['map' => $map, 'centers' => $this->isDelimited($line) ? null : array_map(
                    fn ($s) => ($s['start'] + $s['end']) / 2, self::spans($raw),
                )];
                $previous = null;

                continue;
            }

            $cells = $this->tableCells($raw, $line, $table['centers']);
            if ($cells === null) { // a note or footer running across the table
                $previous = null;
                $result->skip($line);

                continue;
            }

            $map = $table['map'];
            $serial = array_search('serial', $map, true);
            $parsed = $this->fromColumns($cells, $map);
            $isRow = $serial === false || $parsed?->quantity !== null || preg_match('/^\d{1,4}[.)]?$/', $cells[$serial] ?? '');
            if ($isRow) {
                $previous = $this->add($parsed, $line, $result);
            } elseif (! $previous || ! $this->continueLine($previous, $cells, $map)) {
                $previous = null;
                $result->skip($line);
            }
        }
    }

    /**
     * Cells of a table row, indexed like the header. Layout text (columns aligned with spaces) is
     * mapped by position, so a row with an empty cell (no serial number) keeps its columns.
     * Null when a single run of text spans three or more columns: it is not a table row.
     */
    private function tableCells(string $raw, string $line, ?array $centers): ?array
    {
        if ($centers === null || $this->isDelimited($line)) {
            return $this->cells($line);
        }
        $spans = self::spans($raw);
        if (count($spans) === count($centers)) {
            return array_map(fn ($s) => TextNormalizer::clean($s['text']), $spans);
        }

        $cells = array_fill(0, count($centers), '');
        foreach ($spans as $span) {
            $covered = count(array_filter($centers, fn ($c) => $c >= $span['start'] && $c <= $span['end']));
            if ($covered >= 3) {
                return null;
            }
            $middle = ($span['start'] + $span['end']) / 2;
            $distances = array_map(fn ($c) => abs($c - $middle), $centers);
            $column = array_search(min($distances), $distances, true);
            $cells[$column] = trim($cells[$column].' '.TextNormalizer::clean($span['text']));
        }

        return $cells;
    }

    /** Runs of text separated by 2+ spaces, with character columns. */
    private static function spans(string $raw): array
    {
        preg_match_all('/\S+(?: \S+)*/u', $raw, $m, PREG_OFFSET_CAPTURE);

        return array_map(function ($match) use ($raw) {
            $start = mb_strlen(substr($raw, 0, $match[1]));

            return ['text' => $match[0], 'start' => $start, 'end' => $start + mb_strlen($match[0])];
        }, $m[0]);
    }

    private function isDelimited(string $line): bool
    {
        return (bool) preg_match('/[\t|;]/', $line) || substr_count($line, ',') >= 2;
    }

    /** Append a wrapped row's text to the line above. False when it carried nothing to append. */
    private function continueLine(ExtractedLine $line, array $cells, array $map): bool
    {
        $appended = false;
        foreach (['name', 'description', 'notes'] as $field) {
            $i = array_search($field, $map, true);
            $value = $i === false ? '' : trim((string) ($cells[$i] ?? ''));
            if ($value !== '') {
                $line->{$field} = mb_substr(trim(($line->{$field} ?? '').' '.$value), 0, $field === 'name' ? 255 : 1000);
                $appended = true;
            }
        }

        return $appended;
    }

    /** Record a parsed line (or skip the raw text); returns the line when it was kept. */
    private function add(?ExtractedLine $parsed, string $line, ExtractionResult $result): ?ExtractedLine
    {
        if ($parsed && ! $this->isNoise($parsed->name)) {
            return $result->lines[] = $parsed;
        }
        if (! $parsed && ! $this->isNoise($line, prefix: true)) {
            $result->skip($line);
        }

        return null;
    }

    /**
     * Map column index => field from a header row, or null if the row is not a header.
     * One wrong letter is tolerated in longer headers: typed forms have "Quentity", "Product Namc".
     */
    public static function headerMap(array $cells): ?array
    {
        $map = [];
        foreach (array_values($cells) as $i => $cell) {
            $key = trim(preg_replace('/[^a-z ]+/', ' ', mb_strtolower((string) $cell)));
            $key = preg_replace('/\s+/', ' ', $key);
            foreach ([0, 1] as $typos) {
                foreach (self::COLUMNS as $field => $names) {
                    $hit = collect($names)->contains(fn ($n) => $typos === 0 ? $n === $key : strlen($key) >= 5 && levenshtein($n, $key) === 1);
                    if (! in_array($field, $map, true) && $hit) {
                        $map[$i] = $field;
                        break 2;
                    }
                }
            }
        }
        // "description" alone can be the item-name column.
        if (! in_array('name', $map, true) && ($i = array_search('description', $map, true)) !== false) {
            $map[$i] = 'name';
        }

        return in_array('name', $map, true) && in_array('quantity', $map, true) ? $map : null;
    }

    public function fromColumns(array $cells, array $map): ?ExtractedLine
    {
        $cells = array_values($cells);
        $get = fn (string $field) => ($i = array_search($field, $map, true)) !== false ? trim((string) ($cells[$i] ?? '')) : '';

        $name = self::stripNumbering($get('name'));
        $quantity = TextNormalizer::quantity($get('quantity'));
        $unit = $get('unit');

        // "10 Reams" written in the quantity column.
        if ($quantity === null && preg_match('/^'.self::NUM.'\s*(?<unit>[\p{L}.]+)$/u', $get('quantity'), $m)) {
            $quantity = TextNormalizer::quantity($m['qty']);
            $unit = $unit ?: $m['unit'];
        }
        if ($name === '' || ! preg_match('/\p{L}/u', $name)) {
            return null;
        }

        return new ExtractedLine(
            name: mb_substr($name, 0, 255),
            quantity: $quantity,
            unit: $unit !== '' ? mb_substr($unit, 0, 50) : null,
            description: $get('description') ?: null,
            requestedDate: TextNormalizer::date($get('requested_date')),
            department: $get('department') ?: null,
            notes: $get('notes') ?: null,
        );
    }

    private function parseHeader(string $line, ExtractionResult $result): bool
    {
        foreach (self::HEADER_FIELDS as $field => $label) {
            if ($result->{$field} === null && preg_match('/^'.$label.'\s*[:#\-—=]\s*(?<value>.+)$/iu', $line, $m)) {
                $value = trim($m['value']);
                $result->{$field} = match ($field) {
                    'requisitionDate' => TextNormalizer::date($value),
                    default => mb_substr($value, 0, $field === 'title' ? 190 : 100),
                };

                return $result->{$field} !== null;
            }
        }

        return false;
    }

    /** Split table-like text on tabs, pipes, semicolons or runs of 2+ spaces (PDF table columns). */
    private function cells(string $line): array
    {
        $cells = preg_split('/\t|\s*\|\s*|\s*;\s*|\s{2,}/u', trim($line, " |\t"));
        // CSV-style text: "A4 Paper, 10, Ream".
        if (count($cells) < 2 && substr_count($line, ',') >= 2) {
            $cells = explode(',', $line);
        }

        return array_values(array_filter(array_map('trim', $cells), fn ($c) => $c !== ''));
    }

    /** Unlabelled cells: name = first cell with letters, quantity = first numeric cell after it, unit = the cell after. */
    private function fromCells(array $cells): ?ExtractedLine
    {
        // Leading serial number column ("1", "01").
        if (preg_match('/^\d{1,3}[.)]?$/', $cells[0]) && count($cells) > 2) {
            array_shift($cells);
        }
        $nameIdx = null;
        foreach ($cells as $i => $cell) {
            if (preg_match('/\p{L}/u', $cell) && TextNormalizer::quantity($cell) === null) {
                $nameIdx = $i;
                break;
            }
        }
        if ($nameIdx === null) {
            return null;
        }
        for ($i = $nameIdx + 1; $i < count($cells); $i++) {
            if (($qty = TextNormalizer::quantity($cells[$i])) !== null) {
                $unit = $cells[$i + 1] ?? null;
                $unit = $unit !== null && preg_match('/^[\p{L}.]{1,20}$/u', $unit) ? $unit : null;
                $rest = array_slice($cells, $i + ($unit ? 2 : 1));

                return new ExtractedLine(self::stripNumbering($cells[$nameIdx]), $qty, $unit, notes: $rest ? implode(' ', $rest) : null);
            }
            // "10 Reams" in one cell.
            if (preg_match('/^'.self::NUM.'\s*(?<unit>[\p{L}.]+)$/u', $cells[$i], $m)) {
                return new ExtractedLine(self::stripNumbering($cells[$nameIdx]), TextNormalizer::quantity($m['qty']), $m['unit']);
            }
        }

        // A name with no quantity is still worth reviewing.
        return new ExtractedLine(self::stripNumbering($cells[$nameIdx]));
    }

    /** "A4 Paper — 10 Reams", "Marker: 5 pcs", "Barcode 100 pcs (urgent)", "10 Reams A4 Paper", "5 x Marker". */
    private function fromSentence(string $line): ?ExtractedLine
    {
        $line = self::stripBullets($line);
        $unitWord = '(?<unit>[\p{L}][\p{L}.]{0,19})';
        $tail = '(?:\s*[(\[](?<notes>[^)\]]*)[)\]]|\s+[—\-]\s+(?<notes2>.+))?\s*$';

        $patterns = [
            // name <sep> qty [unit] [notes]
            '/^(?<name>.*?\p{L}.*?)\s*[—:|=,]\s*(?:qty\.?\s*)?'.self::NUM.'\s*'.$unitWord.'?'.$tail.'/iu',
            '/^(?<name>.*?\p{L}.*?)\s+-\s+(?:qty\.?\s*)?'.self::NUM.'\s*'.$unitWord.'?'.$tail.'/iu',
            // name qty [unit] [notes] (no separator)
            '/^(?<name>.*?\p{L}.*?)\s+(?:qty\.?\s*)?'.self::NUM.'\s*'.$unitWord.'?'.$tail.'/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $m) && $this->acceptUnit($m['unit'] ?? '', $m)) {
                return new ExtractedLine(
                    self::stripNumbering(rtrim($m['name'], ' -—:,')),
                    TextNormalizer::quantity($m['qty']),
                    ($m['unit'] ?? '') !== '' ? $m['unit'] : null,
                    notes: trim(($m['notes'] ?? '').($m['notes2'] ?? '')) ?: null,
                );
            }
        }

        // qty [unit] [x|of] name
        if (preg_match('/^'.self::NUM.'\s*(?:(?!(?:x|×|of)\s)(?<unit>[\p{L}.]{1,20})\s+)?(?:x|×|of)?\s*(?<name>\p{L}.+)$/iu', $line, $m)) {
            $unit = $m['unit'] ?? '';
            $name = $m['name'];
            if ($unit !== '' && ! ($this->isUnit)($unit)) {
                $name = $unit.' '.$name; // "10 Blue Pens": "Blue" is part of the name
                $unit = '';
            }

            return new ExtractedLine(trim($name), TextNormalizer::quantity($m['qty']), $unit !== '' ? $unit : null);
        }

        return null;
    }

    /** A trailing word after the quantity is a unit only if it is a known unit or nothing follows it. */
    private function acceptUnit(string $unit, array $m): bool
    {
        if ($unit === '') {
            return true;
        }

        return ($this->isUnit)($unit) || (($m['notes'] ?? '') === '' && ($m['notes2'] ?? '') === '');
    }

    /** Exact match for item names ("Total"); prefix match for unparsed lines ("Approved by: …"). */
    private function isNoise(string $text, bool $prefix = false): bool
    {
        $key = preg_replace('/\s+/', ' ', trim(preg_replace('/[^a-z ]+/', ' ', mb_strtolower($text))));
        if (in_array($key, self::NOISE, true) || str_starts_with($key, 'page ')) {
            return true;
        }

        return $prefix && collect(self::NOISE)->contains(fn ($n) => str_starts_with($key, $n.' '));
    }

    private static function stripBullets(string $line): string
    {
        return preg_replace('/^(?:[-•*·▪►✓]+|\(?\d{1,3}[.)\]])\s+/u', '', $line);
    }

    public static function stripNumbering(string $name): string
    {
        return trim(preg_replace('/^(?:[-•*·▪]+\s*|\(?\d{1,3}\s*[.)\]—:-]\s*(?=\D))/u', '', trim($name)));
    }
}
