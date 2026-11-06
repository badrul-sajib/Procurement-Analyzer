<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\Decimal;
use App\Support\Transactions;

/**
 * Records what was actually bought. A line bought short releases the shortfall back to pending,
 * taken from the most recently requested allocations first so the oldest requests stay covered.
 */
class MarkPurchaseOrderPurchased
{
    /** @param  array{purchase_date: string, additional_charges?: string, lines: array<int, array{id: int, purchased_quantity: string, unit_price?: string, discount_amount?: string, tax_amount?: string}>}  $data */
    public function handle(PurchaseOrder $po, array $data, int $userId): PurchaseOrder
    {
        return Transactions::critical(function () use ($po, $data, $userId) {
            $po = PurchaseOrderGuard::lock($po);
            PurchaseOrderGuard::assertStatus($po, [PurchaseOrderStatus::Approved], 'mark as purchased');

            $lines = $po->items()->with('requisitionAllocations')->lockForUpdate()->get()->keyBy('id');
            $input = collect($data['lines'])->keyBy('id');
            if ($unknown = $input->keys()->diff($lines->keys())->all()) {
                throw new BusinessRuleException('unknown_line', 'Some lines do not belong to this purchase order.', ['lines' => ['Unknown: '.implode(', ', $unknown)]]);
            }
            $missing = $lines->keys()->diff($input->keys());
            if ($missing->isNotEmpty()) {
                throw new BusinessRuleException('missing_lines', 'Enter the purchased quantity for every line (0 if nothing was bought).');
            }

            $errors = [];
            $short = false;
            $anyBought = false;
            foreach ($lines as $line) {
                $in = $input[$line->id];
                $bought = Decimal::add($in['purchased_quantity'], 0);
                if (Decimal::cmp($bought, '0') < 0 || Decimal::cmp($bought, $line->ordered_quantity) > 0) {
                    $errors["lines.{$line->id}.purchased_quantity"][] = "Must be between 0 and the ordered {$line->ordered_quantity}.";

                    continue;
                }
                $price = $in['unit_price'] ?? $line->unit_price;
                $discount = $in['discount_amount'] ?? $line->discount_amount;
                if (Decimal::cmp($discount, Decimal::mul($bought, $price, Decimal::MONEY), Decimal::MONEY) > 0) {
                    $errors["lines.{$line->id}.discount_amount"][] = 'Discount cannot exceed the line amount.';

                    continue;
                }
                $short = $short || Decimal::cmp($bought, $line->ordered_quantity) < 0;
                $anyBought = $anyBought || Decimal::isPositive($bought);

                $line->forceFill([
                    'purchased_quantity' => $bought, 'unit_price' => $price, 'discount_amount' => $discount,
                    'tax_amount' => $in['tax_amount'] ?? $line->tax_amount,
                ])->save();
                $this->distribute($line, $bought);
            }
            if ($errors) {
                throw new BusinessRuleException('invalid_purchase', 'Some purchased quantities are invalid.', $errors);
            }
            if (! $anyBought) {
                throw new BusinessRuleException('nothing_purchased', 'Nothing was purchased. Cancel the purchase order instead.');
            }

            $po->forceFill([
                'status' => $short ? PurchaseOrderStatus::PartiallyPurchased : PurchaseOrderStatus::Purchased,
                'purchase_date' => $data['purchase_date'],
                'additional_charges' => $data['additional_charges'] ?? $po->additional_charges,
                'purchased_by' => $userId, 'purchased_at' => now(),
            ])->save();

            AuditLogger::log('purchase_order.purchased', 'purchase_orders', $po->id, "Recorded purchase of {$po->po_number}",
                ['status' => 'approved'], ['status' => $po->status->value, 'purchase_date' => $data['purchase_date'],
                    'lines' => $lines->map(fn ($l) => ['item_id' => $l->item_id, 'ordered' => $l->ordered_quantity, 'purchased' => $l->purchased_quantity])->values()->all()]);
            DataVersion::bump();

            return $po;
        });
    }

    /** Fill allocations oldest-first; whatever is left over is the shortfall on the newest ones. */
    private function distribute($line, string $bought): void
    {
        $remaining = $bought;
        foreach ($line->requisitionAllocations->sortBy('id') as $allocation) {
            $take = Decimal::min($remaining, $allocation->ordered_quantity);
            $allocation->forceFill(['purchased_quantity' => $take])->save();
            $remaining = Decimal::sub($remaining, $take);
        }
    }
}
