<?php

namespace App\Actions\Purchases;

use App\Enums\DeliveryStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use Illuminate\Support\Facades\DB;

/** Close the purchase cycle. Allowed once every purchased quantity has been delivered. */
class CompletePurchaseOrder
{
    public function handle(PurchaseOrder $po, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $userId) {
            $po = PurchaseOrderGuard::lock($po);
            PurchaseOrderGuard::assertStatus($po, [PurchaseOrderStatus::Purchased, PurchaseOrderStatus::PartiallyPurchased], 'complete');

            $undelivered = DB::table('purchase_order_items as poi')
                ->leftJoinSub(
                    DB::table('delivery_items as di')->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
                        ->where('d.status', DeliveryStatus::Confirmed->value)->groupBy('di.purchase_order_item_id')
                        ->select('di.purchase_order_item_id')->selectRaw('SUM(di.quantity) AS delivered'),
                    'dl', 'dl.purchase_order_item_id', '=', 'poi.id')
                ->where('poi.purchase_order_id', $po->id)
                ->whereRaw('COALESCE(dl.delivered, 0) < poi.purchased_quantity')
                ->exists();
            if ($undelivered) {
                throw new BusinessRuleException('not_fully_delivered', 'Some purchased items have not been fully delivered yet.');
            }

            $from = $po->status->value;
            $po->forceFill(['status' => PurchaseOrderStatus::Completed, 'completed_by' => $userId, 'completed_at' => now()])->save();
            AuditLogger::log('purchase_order.completed', 'purchase_orders', $po->id, "Completed {$po->po_number}", ['status' => $from], ['status' => 'completed']);
            DataVersion::bump();

            return $po;
        });
    }
}
