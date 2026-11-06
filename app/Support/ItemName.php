<?php

namespace App\Support;

final class ItemName
{
    /** "  A4-Paper (80gsm) " → "a4 paper 80gsm". Used for exact/alias matching. */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);

        return trim(preg_replace('/\s+/', ' ', $name));
    }
}
