<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/** Human references derived from the auto-increment id: REQ-2026-000123. Gap-tolerant, race-free. */
final class ReferenceNumber
{
    public static function assign(Model $model, string $prefix, string $column): void
    {
        $year = now(config('app.business_timezone'))->format('Y');
        $model->forceFill([$column => sprintf('%s-%s-%06d', $prefix, $year, $model->getKey())])->saveQuietly();
    }
}
