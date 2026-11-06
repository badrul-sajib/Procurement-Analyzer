<?php

namespace App\Actions\Purchases;

use App\Enums\RequisitionStatus;
use App\Exceptions\BusinessRuleException;
use App\Queries\RequisitionFulfilment;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Splits each PO line's quantity across approved requisition lines with pending quantity,
 * oldest requisition first. Must run inside Transactions::critical().
 */
class AllocateRequisitionLines
{
    /** Test seam for deterministic concurrency tests; always null in production. */
    public static ?\Closure $afterLock = null;

    /**
     * @param  array<int, array{item_id: int, quantity: string, requisition_item_ids?: int[]|null}>  $lines
     * @return array<int, array<int, string>> line index => [requisition_item_id => quantity]
     */
    public function handle(array $lines): array
    {
        $itemIds = array_values(array_unique(array_map(fn ($l) => (int) $l['item_id'], $lines)));

        // 1. Candidate lines: approved, for these items, with pending quantity (unlocked pre-read).
        $candidates = $this->candidateIds($itemIds);

        // 2. Lock them in id order (one global order avoids deadlocks), then re-read capacity.
        DB::table('requisition_items')->whereIn('id', $candidates ?: [0])->orderBy('id')->lockForUpdate()->pluck('id');
        if (self::$afterLock) {
            (self::$afterLock)();
        }
        $capacity = $this->capacity($candidates);

        // 3. FIFO allocation per PO line.
        $allocations = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            $remaining = Decimal::add($line['quantity'], 0);
            $allowed = isset($line['requisition_item_ids']) ? array_map('intval', $line['requisition_item_ids']) : null;
            $available = '0';

            foreach ($capacity as $row) {
                if ((int) $row->item_id !== (int) $line['item_id'] || ($allowed !== null && ! in_array((int) $row->id, $allowed, true))) {
                    continue;
                }
                $available = Decimal::add($available, $row->pending);
                if (! Decimal::isPositive($remaining) || ! Decimal::isPositive($row->pending)) {
                    continue;
                }
                $take = Decimal::min($remaining, $row->pending);
                $allocations[$i][$row->id] = $take;
                $row->pending = Decimal::sub($row->pending, $take);
                $remaining = Decimal::sub($remaining, $take);
            }

            if (Decimal::isPositive($remaining)) {
                $errors["lines.{$i}.quantity"][] = sprintf('Only %s is pending for this item; %s requested.', rtrim(rtrim($available, '0'), '.') ?: '0', rtrim(rtrim(Decimal::add($line['quantity'], 0), '0'), '.'));
            }
        }

        if ($errors) {
            throw new BusinessRuleException('over_purchase', 'Purchase quantity exceeds the approved quantity still pending.', $errors);
        }

        return $allocations;
    }

    /** Approved lines of these items that have pending quantity. Unlocked: it only narrows what gets locked. */
    private function candidateIds(array $itemIds): array
    {
        $ids = DB::table('requisition_items as ri')
            ->join('requisitions as r', 'r.id', '=', 'ri.requisition_id')
            ->where('r.status', RequisitionStatus::Approved->value)
            ->whereIn('ri.item_id', $itemIds)
            ->where('ri.approved_quantity', '>', 0)
            ->pluck('ri.id')->all();

        return array_column(array_filter($this->capacity($ids), fn ($row) => Decimal::isPositive($row->pending)), 'id');
    }

    /** Pending per candidate line, read after locking. Ordered oldest requisition first. */
    private function capacity(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $fulfilment = RequisitionFulfilment::query(DB::table('requisition_items')->select('id')->whereIn('id', $ids));

        return DB::table('requisition_items as ri')
            ->join('requisitions as r', 'r.id', '=', 'ri.requisition_id')
            ->leftJoinSub($fulfilment, 'f', 'f.requisition_item_id', '=', 'ri.id')
            ->whereIn('ri.id', $ids)
            ->where('r.status', RequisitionStatus::Approved->value) // re-checked under lock
            ->orderBy('r.requisition_date')->orderBy('r.id')->orderBy('ri.id')
            ->select('ri.id', 'ri.item_id')
            ->selectRaw('ri.approved_quantity - COALESCE(f.purchased, 0) - COALESCE(f.on_order, 0) AS pending')
            ->get()->all();
    }
}
