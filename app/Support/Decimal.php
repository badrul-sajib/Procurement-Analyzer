<?php

namespace App\Support;

/**
 * Exact decimal arithmetic on numeric strings (bcmath). Never use floats for money or quantities.
 * Quantities use scale 3, money scale 2, matching the DECIMAL columns.
 */
final class Decimal
{
    public const QTY = 3;

    public const MONEY = 2;

    public static function add(string|int|null $a, string|int|null $b, int $scale = self::QTY): string
    {
        return bcadd((string) ($a ?? '0'), (string) ($b ?? '0'), $scale);
    }

    public static function sub(string|int|null $a, string|int|null $b, int $scale = self::QTY): string
    {
        return bcsub((string) ($a ?? '0'), (string) ($b ?? '0'), $scale);
    }

    public static function mul(string|int|null $a, string|int|null $b, int $scale = self::QTY): string
    {
        // Compute with extra precision, then round half-up to the target scale (matches MySQL ROUND).
        return self::round(bcmul((string) ($a ?? '0'), (string) ($b ?? '0'), $scale + 6), $scale);
    }

    public static function cmp(string|int|null $a, string|int|null $b, int $scale = self::QTY): int
    {
        return bccomp((string) ($a ?? '0'), (string) ($b ?? '0'), $scale);
    }

    public static function min(string $a, string $b, int $scale = self::QTY): string
    {
        return self::cmp($a, $b, $scale) <= 0 ? $a : $b;
    }

    public static function isPositive(string|int|null $a, int $scale = self::QTY): bool
    {
        return self::cmp($a, '0', $scale) > 0;
    }

    public static function round(string $value, int $scale): string
    {
        return bcround($value, $scale, \RoundingMode::HalfAwayFromZero);
    }
}
