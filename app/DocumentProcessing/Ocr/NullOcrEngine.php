<?php

namespace App\DocumentProcessing\Ocr;

use RuntimeException;

final class NullOcrEngine implements OcrEngine
{
    public function available(): bool
    {
        return false;
    }

    public function pdfToText(string $absolutePath): string
    {
        throw new RuntimeException('OCR is not configured (OCR_DRIVER=null).');
    }
}
