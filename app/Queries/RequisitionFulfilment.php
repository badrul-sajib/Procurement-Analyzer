<?php

namespace App\Queries;

use App\Enums\DeliveryStatus;
use App\Enums\PurchaseOrderStatus;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of per-requisition-line fulfilment (docs/database.md "Derived quantities"):
 *   on_order  = Σ allocation.ordered_quantity on draft / pending / approved POs
 *   purchased = Σ allocation.purchased_quantity on purchased / partially purchased / completed POs
 *   delivered = FIFO share of each PO line's confirmed deliveries, in allocation order
 * Nothing is stored: every figure is computed from transaction rows.
 */
final class RequisitionFulfilment
{
    /**
     * Derived table (requisition_item_id, on_order, purchased, delivered) for the given line ids.
     *
     * @param  Builder  $lineIds  subquery selecting requisition_items.id
     */
    public static function query(Builder $lineIds): Builder
    {
        $poiIds = DB::table('purchase_order_item_requisitions')->select('purchase_order_item_id')->whereIn('requisition_item_id', $lineIds);
        $onOrder = self::inList(PurchaseOrderStatus::onOrder());
        $purchased = self::inList(PurchaseOrderStatus::purchased());

        $allocations = DB::table('purchase_order_item_requisitions as a')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'a.purchase_order_item_id')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('po.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->whereIn('a.purchase_order_item_id', $poiIds)
            ->select('a.id', 'a.requisition_item_id', 'a.purchase_order_item_id')
            ->selectRaw("CASE WHEN po.status IN ({$onOrder}) THEN a.ordered_quantity ELSE 0 END AS on_order")
            ->selectRaw("CASE WHEN po.status IN ({$purchased}) THEN COALESCE(a.purchased_quantity, 0) ELSE 0 END AS purchased");

        $delivered = DB::table('delivery_items as di')
            ->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->where('d.status', DeliveryStatus::Confirmed->value)
            ->whereIn('di.purchase_order_item_id', $poiIds)
            ->groupBy('di.purchase_order_item_id')
            ->select('di.purchase_order_item_id')->selectRaw('SUM(di.quantity) AS qty');

        $ranked = DB::query()->fromSub($allocations, 'al')
            ->leftJoinSub($delivered, 'dl', 'dl.purchase_order_item_id', '=', 'al.purchase_order_item_id')
            ->select('al.*')
            ->selectRaw('SUM(al.purchased) OVER (PARTITION BY al.purchase_order_item_id ORDER BY al.id) AS running')
            ->selectRaw('COALESCE(dl.qty, 0) AS line_delivered');

        return DB::query()->fromSub($ranked, 'r')
            ->whereIn('r.requisition_item_id', $lineIds)
            ->groupBy('r.requisition_item_id')
            ->select('r.requisition_item_id')
            ->selectRaw('SUM(r.on_order) AS on_order')
            ->selectRaw('SUM(r.purchased) AS purchased')
            ->selectRaw('SUM(LEAST(r.purchased, GREATEST(0, r.line_delivered - (r.running - r.purchased)))) AS delivered');
    }

    /** @return array<int, array{approved: ?string, on_order: string, purchased: string, pending: ?string, delivered: string, remaining_delivery: string}> */
    public static function forRequisition(int $requisitionId): array
    {
        $lines = DB::table('requisition_items')->where('requisition_id', $requisitionId)->pluck('approved_quantity', 'id');
        $rows = self::query(DB::table('requisition_items')->select('id')->where('requisition_id', $requisitionId))->get()->keyBy('requisition_item_id');

        return $lines->map(function ($approved, $id) use ($rows) {
            $row = $rows->get($id);

            return self::shape($approved, $row->on_order ?? '0', $row->purchased ?? '0', $row->delivered ?? '0');
        })->all();
    }

    public static function shape(?string $approved, string $onOrder, string $purchased, string $delivered): array
    {
        $onOrder = Decimal::add($onOrder, 0);
        $purchased = Decimal::add($purchased, 0);
        $delivered = Decimal::add($delivered, 0);

        return [
            'approved' => $approved,
            'on_order' => $onOrder,
            'purchased' => $purchased,
            'pending' => $approved === null ? null : Decimal::sub(Decimal::sub($approved, $purchased), $onOrder),
            'delivered' => $delivered,
            'remaining_delivery' => Decimal::sub($purchased, $delivered),
        ];
    }

    /** Enum values are compile-time constants, so inlining them is injection-safe. */
    private static function inList(array $values): string
    {
        return implode(',', array_map(fn ($v) => "'".addslashes($v)."'", $values));
    }
}
