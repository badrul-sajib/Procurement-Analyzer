<?php

namespace App\DocumentProcessing\Extractors;

use App\DocumentProcessing\DocumentType;
use App\DocumentProcessing\ExtractionResult;
use App\DocumentProcessing\LineParser;
use App\DocumentProcessing\Ocr\OcrEngine;
use App\DocumentProcessing\ReferenceResolver;
use App\Support\Settings;
use Illuminate\Support\Facades\Process;
use Maatwebsite\Excel\DefaultValueBinder;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser as PdfParser;

final class DocumentExtractor
{
    /** Below this many letters per page a PDF is treated as scanned (image-only). */
    private const MIN_LETTERS_PER_PAGE = 20;

    public function __construct(private OcrEngine $ocr) {}

    public function extract(string $absolutePath, DocumentType $type, ReferenceResolver $references): ExtractionResult
    {
        $parser = new LineParser(fn (string $unit) => $references->isUnit($unit));

        return match ($type) {
            DocumentType::Txt => $parser->parse($this->readText($absolutePath), 'text'),
            DocumentType::Pdf => $this->pdf($absolutePath, $parser),
            DocumentType::Xlsx, DocumentType::Xls => $this->spreadsheet($absolutePath, $parser),
        };
    }

    private function readText(string $path): string
    {
        $text = file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text); // UTF-8 BOM
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return $text;
    }

    private function pdf(string $path, LineParser $parser): ExtractionResult
    {
        [$text, $pages] = $this->pdfLayoutText($path) ?? $this->pdfParserText($path);

        if (preg_match_all('/\p{L}/u', $text) >= self::MIN_LETTERS_PER_PAGE * $pages) {
            return $parser->parse($text, 'pdf_text');
        }

        if (! Settings::get('ocr_enabled') || ! $this->ocr->available()) {
            throw new RuntimeException('This PDF has no text layer (scanned image) and OCR is not available. Enter the lines manually or enable OCR.');
        }

        return $parser->parse($this->ocr->pdfToText($path), 'ocr');
    }

    /**
     * Poppler's pdftotext -layout keeps table columns aligned, which the parser needs to tell the
     * name column from the quantity column and to join names that wrap onto a second line.
     *
     * @return array{string, int}|null null when pdftotext is not installed or fails
     */
    private function pdfLayoutText(string $path): ?array
    {
        try {
            $run = Process::timeout(120)->run([config('services.ocr.pdftotext_binary'), '-layout', '-enc', 'UTF-8', $path, '-']);
        } catch (\Throwable) {
            return null;
        }

        return $run->successful() ? [$run->output(), max(1, substr_count($run->output(), "\f"))] : null;
    }

    /** @return array{string, int} */
    private function pdfParserText(string $path): array
    {
        $config = new Config;
        $config->setRetainImageContent(false); // text only: keeps memory bounded on image-heavy files
        $document = (new PdfParser([], $config))->parseFile($path);

        return [$document->getText(), max(1, count($document->getPages()))];
    }

    private function spreadsheet(string $path, LineParser $parser): ExtractionResult
    {
        $result = new ExtractionResult('excel');
        Cell::setValueBinder(new DefaultValueBinder); // never inherit a binder left behind by an export
        $import = new SpreadsheetImport($result, $parser);
        Excel::import($import, $path);
        $import->finish();

        return $result;
    }
}
