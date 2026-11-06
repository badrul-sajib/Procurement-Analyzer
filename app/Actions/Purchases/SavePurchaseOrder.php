<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\Decimal;
use App\Support\ReferenceNumber;
use App\Support\Transactions;

/** Create a draft PO, or replace a draft's lines. Quantities are allocated to pending requisition lines. */
class SavePurchaseOrder
{
    public function __construct(private AllocateRequisitionLines $allocate) {}

    public function handle(array $data, ?PurchaseOrder $po, int $userId): PurchaseOrder
    {
        return Transactions::critical(function () use ($data, $po, $userId) {
            $before = null;
            if ($po) {
                $po = PurchaseOrderGuard::lock($po);
                PurchaseOrderGuard::assertStatus($po, [PurchaseOrderStatus::Draft], 'edit');
            }
            $vendor = Vendor::findOrFail($data['vendor_id'] ?? $po->vendor_id);
            if (! $vendor->is_active) {
                throw new BusinessRuleException('vendor_inactive', "{$vendor->name} is inactive.", ['vendor_id' => ['Vendor is inactive.']]);
            }
            $this->assertNonNegativeTotals($data['lines']);

            if ($po) {
                $before = ['vendor_id' => $po->vendor_id, 'lines' => $po->items()->get(['item_id', 'ordered_quantity', 'unit_price'])->toArray()];
                $po->items()->delete(); // cascades allocations; capacity is re-read below without them
                $po->update(array_intersect_key($data, array_flip(['vendor_id', 'order_date', 'additional_charges', 'notes'])));
            } else {
                $po = PurchaseOrder::create([
                    'vendor_id' => $vendor->id,
                    'order_date' => $data['order_date'],
                    'additional_charges' => $data['additional_charges'] ?? 0,
                    'notes' => $data['notes'] ?? null,
                    'status' => PurchaseOrderStatus::Draft,
                    'created_by' => $userId,
                ]);
                ReferenceNumber::assign($po, 'PO', 'po_number');
            }

            $allocations = $this->allocate->handle($data['lines']);
            foreach (array_values($data['lines']) as $i => $line) {
                $poLine = $po->items()->create([
                    'item_id' => $line['item_id'],
                    'ordered_quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount_amount'] ?? 0,
                    'tax_amount' => $line['tax_amount'] ?? 0,
                    'notes' => $line['notes'] ?? null,
                ]);
                foreach ($allocations[$i] as $requisitionItemId => $quantity) {
                    $poLine->requisitionAllocations()->create(['requisition_item_id' => $requisitionItemId, 'ordered_quantity' => $quantity]);
                }
            }

            AuditLogger::log($before ? 'purchase_order.updated' : 'purchase_order.created', 'purchase_orders', $po->id,
                ($before ? 'Updated ' : 'Created ')."{$po->po_number} for {$vendor->name}",
                $before, ['vendor_id' => $vendor->id, 'lines' => array_map(fn ($l) => array_intersect_key($l, array_flip(['item_id', 'quantity', 'unit_price'])), $data['lines'])]);
            DataVersion::bump();

            return $po;
        });
    }

    private function assertNonNegativeTotals(array $lines): void
    {
        $errors = [];
        foreach (array_values($lines) as $i => $line) {
            $gross = Decimal::mul($line['quantity'], $line['unit_price'], Decimal::MONEY);
            if (Decimal::cmp($line['discount_amount'] ?? 0, $gross, Decimal::MONEY) > 0) {
                $errors["lines.{$i}.discount_amount"][] = 'Discount cannot exceed the line amount.';
            }
        }
        if ($errors) {
            throw new BusinessRuleException('invalid_discount', 'A discount is larger than its line amount.', $errors);
        }
    }
}
