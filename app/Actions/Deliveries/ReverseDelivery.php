<?php

namespace App\Actions\Deliveries;

use App\Enums\DeliveryStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use Illuminate\Support\Facades\DB;

/** Deliveries are never deleted: a reversal keeps the record, who reversed it, when and why. */
class ReverseDelivery
{
    public function handle(Delivery $delivery, string $reason, int $userId): Delivery
    {
        return DB::transaction(function () use ($delivery, $reason, $userId) {
            $delivery = Delivery::lockForUpdate()->findOrFail($delivery->id);
            if ($delivery->status === DeliveryStatus::Reversed) {
                throw new BusinessRuleException('invalid_status', 'This delivery is already reversed.');
            }
            $po = PurchaseOrder::lockForUpdate()->findOrFail($delivery->purchase_order_id);
            if ($po->status === PurchaseOrderStatus::Completed) {
                throw new BusinessRuleException('po_completed', "{$po->po_number} is completed and closed; its deliveries can't be reversed.");
            }

            $from = $delivery->status->value;
            $delivery->forceFill(['status' => DeliveryStatus::Reversed, 'reversed_by' => $userId, 'reversed_at' => now(), 'reversal_reason' => $reason])->save();
            AuditLogger::log('delivery.reversed', 'deliveries', $delivery->id, "Reversed {$delivery->delivery_number}: {$reason}", ['status' => $from], ['status' => 'reversed']);
            DataVersion::bump();

            return $delivery;
        });
    }
}
