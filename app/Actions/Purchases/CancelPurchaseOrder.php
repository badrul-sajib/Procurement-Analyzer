<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\Transactions;

/** Cancelled POs keep their lines for history but stop counting; their quantities return to pending. */
class CancelPurchaseOrder
{
    public function handle(PurchaseOrder $po, string $reason, int $userId): PurchaseOrder
    {
        return Transactions::critical(function () use ($po, $reason, $userId) {
            $po = PurchaseOrderGuard::lock($po);
            PurchaseOrderGuard::assertStatus($po, [
                PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Approved,
                PurchaseOrderStatus::Purchased, PurchaseOrderStatus::PartiallyPurchased,
            ], 'cancel');
            if (PurchaseOrderGuard::hasDeliveries($po)) {
                throw new BusinessRuleException('has_deliveries', 'Reverse the deliveries recorded against this purchase order first.');
            }
            if (PurchaseOrderGuard::hasActivePayments($po)) {
                throw new BusinessRuleException('has_payments', 'Payments are allocated to this purchase order. Reverse them first.');
            }

            $from = $po->status->value;
            $po->forceFill(['status' => PurchaseOrderStatus::Cancelled, 'cancelled_by' => $userId, 'cancelled_at' => now(), 'cancellation_reason' => $reason])->save();
            AuditLogger::log('purchase_order.cancelled', 'purchase_orders', $po->id, "Cancelled {$po->po_number}: {$reason}", ['status' => $from], ['status' => 'cancelled']);
            DataVersion::bump();

            return $po;
        });
    }
}
