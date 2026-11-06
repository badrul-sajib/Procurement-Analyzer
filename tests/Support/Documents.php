<?php

namespace Tests\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Real files (not fakes) so MIME sniffing and parsers run exactly as in production. */
final class Documents
{
    public static function text(string $content, string $name = 'requisition.txt'): UploadedFile
    {
        return self::fromContent($content, $name);
    }

    /** @param  array<int, array<int, mixed>>  $rows */
    public static function xlsx(array $rows, string $name = 'requisition.xlsx'): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($sheet))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    public static function pdf(string $html, string $name = 'requisition.pdf'): UploadedFile
    {
        return self::fromContent(Pdf::loadHTML($html)->output(), $name);
    }

    public static function fromContent(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
