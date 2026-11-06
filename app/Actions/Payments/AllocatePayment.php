<?php

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Queries\VendorBalance;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\Decimal;
use App\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Applies a payment to purchase orders. A payment can't be allocated beyond its amount and a
 * PO can't be paid beyond its total; both are re-checked under row locks.
 */
class AllocatePayment
{
    /** @param  array<int, array{purchase_order_id: int, amount: string}>  $allocations */
    public function handle(Payment $payment, array $allocations, int $userId): Payment
    {
        return Transactions::critical(function () use ($payment, $allocations, $userId) {
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== PaymentStatus::Active) {
                throw new BusinessRuleException('payment_reversed', 'Reversed payments cannot be allocated.');
            }

            $poIds = collect($allocations)->pluck('purchase_order_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $pos = PurchaseOrder::whereIn('id', $poIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $totals = DB::query()->fromSub(VendorBalance::poTotals(), 't')->whereIn('t.id', $poIds)->pluck('grand_total', 'id');
            $paid = VendorBalance::poPaid()->whereIn('pa.purchase_order_id', $poIds)->pluck('paid', 'purchase_order_id');

            $alreadyAllocated = (string) $payment->allocations()->sum('amount');
            $requested = '0.00';
            $errors = [];
            foreach (array_values($allocations) as $i => $allocation) {
                $po = $pos->get((int) $allocation['purchase_order_id']);
                $amount = Decimal::add($allocation['amount'], 0, Decimal::MONEY);
                $requested = Decimal::add($requested, $amount, Decimal::MONEY);

                if (! $po || $po->vendor_id !== $payment->vendor_id) {
                    $errors["allocations.{$i}.purchase_order_id"][] = 'The purchase order belongs to a different vendor.';
                } elseif (! in_array($po->status->value, PurchaseOrderStatus::purchased(), true)) {
                    $errors["allocations.{$i}.purchase_order_id"][] = "{$po->po_number} is not purchased, so nothing is owed on it yet.";
                } else {
                    $due = Decimal::sub($totals[$po->id] ?? 0, $paid[$po->id] ?? 0, Decimal::MONEY);
                    if (Decimal::cmp($amount, $due, Decimal::MONEY) > 0) {
                        $errors["allocations.{$i}.amount"][] = "Only {$due} is due on {$po->po_number}.";
                    }
                    $paid[$po->id] = Decimal::add($paid[$po->id] ?? 0, $amount, Decimal::MONEY); // two lines to the same PO
                }
            }
            $unallocated = Decimal::sub($payment->amount, $alreadyAllocated, Decimal::MONEY);
            if (Decimal::cmp($requested, $unallocated, Decimal::MONEY) > 0) {
                $errors['allocations'][] = "Only {$unallocated} of this payment is unallocated; {$requested} requested.";
            }
            if ($errors) {
                throw new BusinessRuleException('over_allocation', 'Allocation exceeds what is available or due.', $errors);
            }

            foreach ($allocations as $allocation) {
                $payment->allocations()->create(['purchase_order_id' => $allocation['purchase_order_id'], 'amount' => $allocation['amount'], 'created_by' => $userId]);
            }
            AuditLogger::log('payment.allocated', 'payments', $payment->id, "Allocated {$requested} of {$payment->payment_reference}", null, ['allocations' => $allocations]);
            DataVersion::bump();

            return $payment;
        });
    }
}
