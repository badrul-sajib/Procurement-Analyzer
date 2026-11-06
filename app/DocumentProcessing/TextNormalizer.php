<?php

namespace App\DocumentProcessing;

use Carbon\CarbonImmutable;

/** Shared value parsing for every extractor. Input is untrusted document text. */
final class TextNormalizer
{
    private const BENGALI_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    public static function clean(string $line): string
    {
        $line = str_replace(self::BENGALI_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $line);
        $line = preg_replace('/[\x{2012}-\x{2015}\x{2212}]/u', '—', $line);           // dash variants
        $line = preg_replace('/[\x{00A0}\x{2000}-\x{200B}]/u', ' ', $line);           // odd spaces
        $line = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $line);        // control chars

        return trim($line);
    }

    /** "1,000.50" → "1000.500"; null when not a non-negative number. */
    public static function quantity(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? number_format((float) $value, 3, '.', '') : null;
        }
        $value = str_replace(',', '', self::clean((string) $value));
        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return bcadd($value, '0', 3);
    }

    /** Day-first (Bangladesh convention) date parsing. Returns Y-m-d or null. */
    public static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $value = trim(preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', (string) $value));
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'd M Y', 'd F Y', 'd-M-Y', 'd M, Y', 'M d, Y', 'F d, Y', 'd F, Y'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (\Throwable) {
                continue;
            }
            $errors = CarbonImmutable::getLastErrors();
            if ($date && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->year >= 2000 && $date->year <= 2100) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
