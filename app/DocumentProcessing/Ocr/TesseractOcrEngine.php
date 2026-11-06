<?php

namespace App\DocumentProcessing\Ocr;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/** pdftoppm (Poppler) rasterizes each page at 300 DPI, tesseract reads each image. */
final class TesseractOcrEngine implements OcrEngine
{
    public function __construct(private array $config) {}

    public function available(): bool
    {
        return Process::run([$this->config['tesseract_binary'], '--version'])->successful()
            && Process::run([$this->config['pdftoppm_binary'], '-v'])->successful();
    }

    public function pdfToText(string $absolutePath): string
    {
        $dir = storage_path('app/ocr/'.Str::uuid());
        File::ensureDirectoryExists($dir);

        try {
            // Argument arrays, never a shell string: file names cannot inject commands.
            $raster = Process::timeout($this->config['timeout'])
                ->run([$this->config['pdftoppm_binary'], '-r', '300', '-gray', '-png', '-l', '50', $absolutePath, $dir.'/page']);
            if (! $raster->successful()) {
                throw new RuntimeException('Could not rasterize PDF: '.Str::limit($raster->errorOutput(), 200));
            }

            $text = [];
            foreach (collect(File::files($dir))->sortBy(fn ($f) => $f->getFilename()) as $page) {
                $ocr = Process::timeout($this->config['timeout'])
                    ->run([$this->config['tesseract_binary'], $page->getPathname(), 'stdout', '-l', $this->config['languages'], '--psm', '6']);
                if (! $ocr->successful()) {
                    throw new RuntimeException('OCR failed: '.Str::limit($ocr->errorOutput(), 200));
                }
                $text[] = $ocr->output();
            }

            return implode("\n", $text);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
