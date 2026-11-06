<?php

namespace App\Actions\Deliveries;

use App\Enums\DeliveryStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\Decimal;
use App\Support\Transactions;
use Illuminate\Support\Facades\DB;

/** The point where received goods count as delivered. Re-checks capacity under lock. */
class ConfirmDelivery
{
    public function handle(Delivery $delivery, int $userId): Delivery
    {
        return Transactions::critical(function () use ($delivery, $userId) {
            $delivery = Delivery::lockForUpdate()->with('items')->findOrFail($delivery->id);
            if ($delivery->status !== DeliveryStatus::Pending) {
                throw new BusinessRuleException('invalid_status', "Only pending deliveries can be confirmed; this one is {$delivery->status->value}.");
            }
            PurchaseOrder::lockForUpdate()->findOrFail($delivery->purchase_order_id);
            DB::table('purchase_order_items')->where('purchase_order_id', $delivery->purchase_order_id)->orderBy('id')->lockForUpdate()->pluck('id');

            $remaining = DeliveryCapacity::remaining($delivery->purchase_order_id);
            foreach ($delivery->items as $line) {
                if (Decimal::cmp($line->quantity, $remaining[$line->purchase_order_item_id] ?? '0') > 0) {
                    throw new BusinessRuleException('over_delivery', 'Confirming this delivery would exceed the purchased quantity.', [
                        "lines.{$line->id}.quantity" => ["Only {$remaining[$line->purchase_order_item_id]} is left to deliver."],
                    ]);
                }
            }

            $delivery->forceFill(['status' => DeliveryStatus::Confirmed, 'confirmed_by' => $userId, 'confirmed_at' => now(), 'received_by' => $delivery->received_by ?? $userId])->save();
            AuditLogger::log('delivery.confirmed', 'deliveries', $delivery->id, "Confirmed {$delivery->delivery_number}", ['status' => 'pending'], ['status' => 'confirmed']);
            DataVersion::bump();

            return $delivery;
        });
    }
}
