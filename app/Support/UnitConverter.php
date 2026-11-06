<?php

namespace App\Support;

use App\Models\UnitConversion;

/** Resolves "1 {from} = factor × {item default unit}": item rule first, then global. No chaining. */
final class UnitConverter
{
    public static function factor(int $itemId, int $defaultUnitId, int $fromUnitId): ?string
    {
        if ($fromUnitId === $defaultUnitId) {
            return '1.000000';
        }

        $rule = UnitConversion::query()
            ->where('from_unit_id', $fromUnitId)
            ->where('to_unit_id', $defaultUnitId)
            ->where(fn ($q) => $q->where('item_id', $itemId)->orWhereNull('item_id'))
            ->orderByRaw('item_id IS NULL') // item-specific (0) before global (1)
            ->first();

        return $rule?->factor;
    }
}
