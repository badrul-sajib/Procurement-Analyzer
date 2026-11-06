<?php

namespace App\DocumentProcessing;

use Illuminate\Http\UploadedFile;

enum DocumentType: string
{
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';
    case Xls = 'xls';
    case Txt = 'txt';

    /** MIME types libmagic reports for each format (content sniffed, never the client's claim). */
    private const MIME = [
        'pdf' => ['application/pdf'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office'],
        // libmagic reports plain text that starts with "Date:" or "From:" lines as an e-mail message.
        'txt' => ['text/plain', 'text/csv', 'text/tab-separated-values', 'message/rfc822', 'message/news'],
    ];

    /** Null when the extension is unsupported or the content doesn't match it. */
    public static function detect(UploadedFile $file): ?self
    {
        $type = self::tryFrom(strtolower($file->getClientOriginalExtension()));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';

        if (! $type || ! in_array($mime, self::MIME[$type->value], true)) {
            return null;
        }
        // Text must really be text: no NUL bytes anywhere in the first 64 KB.
        if ($type === self::Txt && str_contains((string) file_get_contents($file->getRealPath(), false, null, 0, 65536), "\0")) {
            return null;
        }

        return $type;
    }

    public function storedMime(): string
    {
        return self::MIME[$this->value][0];
    }
}
