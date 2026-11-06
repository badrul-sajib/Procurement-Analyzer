<?php

use App\DocumentProcessing\Ocr\TesseractOcrEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Process;

it('reads a scanned (image-only) PDF with Tesseract', function () {
    $engine = new TesseractOcrEngine(config('services.ocr'));
    if (! $engine->available()) {
        $this->markTestSkipped('tesseract/pdftoppm not installed');
    }

    // Build a genuinely scanned PDF: render text, rasterize it, then embed only the image.
    $dir = sys_get_temp_dir().'/ocr-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/text.pdf", Pdf::loadHTML('<div style="font-size:28px;font-family:Helvetica">A4 Paper - 10 Reams<br>Marker - 5 pcs</div>')->output());
    Process::run(['pdftoppm', '-r', '150', '-png', '-singlefile', "{$dir}/text.pdf", "{$dir}/scan"])->throw();
    $png = base64_encode(file_get_contents("{$dir}/scan.png"));
    file_put_contents("{$dir}/scanned.pdf", Pdf::loadHTML("<img src=\"data:image/png;base64,{$png}\" style=\"width:100%\">")->output());

    $text = $engine->pdfToText("{$dir}/scanned.pdf");

    expect($text)->toContain('A4 Paper')->toContain('Reams')->toContain('Marker');
    array_map('unlink', glob("{$dir}/*"));
    rmdir($dir);
});
