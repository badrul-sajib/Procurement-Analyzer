<?php

namespace Tests\Support;

use App\Actions\Requisitions\ApproveRequisition;
use App\Actions\Requisitions\VerifyRequisition;
use App\Enums\RequisitionStatus;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\Unit;
use App\Models\User;

/** Builds requisitions through the real workflow actions so tests exercise production rules. */
final class Scenario
{
    /**
     * @param  array<int, array{0: Item, 1: string|int, 2?: string}>  $lines  [item, quantity, unit code (default: item's unit)]
     * @param  array<int, string|int>  $approve  line index => approved quantity (default: requested)
     */
    public static function requisition(array $lines, string $status = 'approved', string $date = '2026-11-01', array $approve = [], array $attrs = []): Requisition
    {
        $user = User::first() ?? User::factory()->create();
        $requisition = Requisition::factory()->create(['requisition_date' => $date, 'status' => RequisitionStatus::Draft, 'created_by' => $user->id] + $attrs);

        foreach (array_values($lines) as $i => $line) {
            [$item, $qty] = $line;
            $unitId = isset($line[2]) ? Unit::where('code', $line[2])->value('id') : $item->default_unit_id;
            $requisition->items()->create([
                'line_no' => $i + 1, 'item_id' => $item->id, 'original_name' => $item->name, 'quantity' => (string) $qty,
                'unit_id' => $unitId, 'department_id' => $attrs['department_id'] ?? null,
            ]);
        }

        if ($status === 'draft') {
            return $requisition;
        }
        app(VerifyRequisition::class)->handle($requisition, $user->id);
        if ($status === 'approved') {
            $ids = $requisition->items()->orderBy('line_no')->pluck('id')->all();
            $approved = collect($approve)->mapWithKeys(fn ($q, $i) => [$ids[$i] => (string) $q])->all();
            app(ApproveRequisition::class)->handle($requisition, $approved, $user->id);
        }

        return $requisition->fresh('items');
    }
}
