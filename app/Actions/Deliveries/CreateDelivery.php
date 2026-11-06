<?php

namespace App\Actions\Deliveries;

use App\Enums\DeliveryStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\Decimal;
use App\Support\ReferenceNumber;
use App\Support\Transactions;

/** Records goods received against a purchased PO. Counts toward delivered only once confirmed. */
class CreateDelivery
{
    public function __construct(private ConfirmDelivery $confirm) {}

    public function handle(array $data, int $userId, bool $confirmNow = false): Delivery
    {
        return Transactions::critical(function () use ($data, $userId, $confirmNow) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($data['purchase_order_id']);
            if (! in_array($po->status, [PurchaseOrderStatus::Purchased, PurchaseOrderStatus::PartiallyPurchased], true)) {
                throw new BusinessRuleException('po_not_deliverable', "Deliveries can only be recorded for purchased orders; {$po->po_number} is ".str_replace('_', ' ', $po->status->value).'.');
            }

            // Reserve against pending deliveries too, so two open receipts can't both claim the same goods.
            $remaining = DeliveryCapacity::remaining($po->id, includePending: true);
            $errors = [];
            foreach (array_values($data['lines']) as $i => $line) {
                $lineId = (int) $line['purchase_order_item_id'];
                if (! array_key_exists($lineId, $remaining)) {
                    $errors["lines.{$i}.purchase_order_item_id"][] = 'This line is not on the purchase order.';
                } elseif (Decimal::cmp($line['quantity'], $remaining[$lineId]) > 0) {
                    $errors["lines.{$i}.quantity"][] = "Only {$remaining[$lineId]} is left to deliver.";
                }
            }
            if ($errors) {
                throw new BusinessRuleException('over_delivery', 'Delivered quantity cannot exceed the purchased quantity.', $errors);
            }

            $delivery = Delivery::create([
                'purchase_order_id' => $po->id,
                'delivery_date' => $data['delivery_date'],
                'delivery_reference' => $data['delivery_reference'] ?? null,
                'received_by' => $data['received_by'] ?? $userId,
                'notes' => $data['notes'] ?? null,
                'status' => DeliveryStatus::Pending,
                'created_by' => $userId,
            ]);
            ReferenceNumber::assign($delivery, 'DLV', 'delivery_number');
            foreach ($data['lines'] as $line) {
                $delivery->items()->create(['purchase_order_item_id' => $line['purchase_order_item_id'], 'quantity' => $line['quantity'], 'notes' => $line['notes'] ?? null]);
            }
            AuditLogger::log('delivery.created', 'deliveries', $delivery->id, "Recorded {$delivery->delivery_number} against {$po->po_number}", null, ['lines' => $data['lines']]);
            DataVersion::bump();

            return $confirmNow ? $this->confirm->handle($delivery, $userId) : $delivery;
        });
    }
}
