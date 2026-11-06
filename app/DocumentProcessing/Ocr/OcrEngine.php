<?php

namespace App\DocumentProcessing\Ocr;

/** Replaceable OCR provider (Tesseract locally; a cloud OCR API can implement the same contract). */
interface OcrEngine
{
    public function available(): bool;

    /** Plain text of every page of a scanned PDF. */
    public function pdfToText(string $absolutePath): string;
}
