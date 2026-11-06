<?php

namespace App\Actions\Deliveries;

use App\Enums\DeliveryStatus;
use Illuminate\Support\Facades\DB;

final class DeliveryCapacity
{
    /**
     * Remaining deliverable quantity per PO line: purchased − confirmed deliveries (− pending
     * deliveries when $includePending). Lock the PO lines first when deciding under contention.
     *
     * @return array<int, string> purchase_order_item_id => remaining
     */
    public static function remaining(int $purchaseOrderId, bool $includePending = false, ?int $exceptDeliveryId = null): array
    {
        $statuses = $includePending ? [DeliveryStatus::Confirmed->value, DeliveryStatus::Pending->value] : [DeliveryStatus::Confirmed->value];
        $delivered = DB::table('delivery_items as di')->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->whereIn('d.status', $statuses)
            ->when($exceptDeliveryId, fn ($q, $id) => $q->where('d.id', '!=', $id))
            ->groupBy('di.purchase_order_item_id')
            ->select('di.purchase_order_item_id')->selectRaw('SUM(di.quantity) AS qty');

        return DB::table('purchase_order_items as poi')
            ->leftJoinSub($delivered, 'dl', 'dl.purchase_order_item_id', '=', 'poi.id')
            ->where('poi.purchase_order_id', $purchaseOrderId)
            ->selectRaw('poi.id, COALESCE(poi.purchased_quantity, 0) - COALESCE(dl.qty, 0) AS remaining')
            ->pluck('remaining', 'id')
            ->map(fn ($v) => bcadd((string) $v, '0', 3))
            ->all();
    }
}
