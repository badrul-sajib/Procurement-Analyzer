<?php

namespace App\Console\Commands;

use App\Enums\DeliveryStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Queries\RequisitionFulfilment;
use App\Queries\VendorBalance;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Re-verifies every quantity and money invariant from raw rows. Scheduled daily; exits 1 on any violation. */
#[Signature('integrity:check')]
#[Description('Verify purchase, delivery and payment invariants against the raw transaction rows')]
class IntegrityCheck extends Command
{
    public function handle(): int
    {
        $checks = [
            'PO line ordered quantity ≠ Σ requisition allocations' => DB::table('purchase_order_items as poi')
                ->join(DB::raw('(SELECT purchase_order_item_id, SUM(ordered_quantity) o, SUM(purchased_quantity) p, COUNT(purchased_quantity) pc, COUNT(*) c FROM purchase_order_item_requisitions GROUP BY purchase_order_item_id) a'), 'a.purchase_order_item_id', '=', 'poi.id')
                ->whereRaw('a.o <> poi.ordered_quantity OR (poi.purchased_quantity IS NOT NULL AND (a.pc <> a.c OR a.p <> poi.purchased_quantity))')
                ->pluck('poi.id'),
            'Confirmed deliveries exceed purchased quantity' => DB::table('purchase_order_items as poi')
                ->join(DB::raw("(SELECT di.purchase_order_item_id, SUM(di.quantity) q FROM delivery_items di JOIN deliveries d ON d.id = di.delivery_id WHERE d.status = '".DeliveryStatus::Confirmed->value."' GROUP BY di.purchase_order_item_id) dl"), 'dl.purchase_order_item_id', '=', 'poi.id')
                ->whereRaw('dl.q > COALESCE(poi.purchased_quantity, 0)')->pluck('poi.id'),
            'Payment allocations exceed the payment amount' => DB::table('payments as p')
                ->join(DB::raw('(SELECT payment_id, SUM(amount) a FROM payment_allocations GROUP BY payment_id) al'), 'al.payment_id', '=', 'p.id')
                ->where('p.status', PaymentStatus::Active->value)->whereRaw('al.a > p.amount')->pluck('p.id'),
            'Active payments exceed a PO total' => DB::query()->fromSub(VendorBalance::poTotals(), 't')
                ->joinSub(VendorBalance::poPaid(), 'pp', 'pp.purchase_order_id', '=', 't.id')->whereRaw('pp.paid > t.grand_total')->pluck('t.id'),
            'Payments allocated to POs that are not payable' => DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
                ->join('purchase_orders as po', 'po.id', '=', 'pa.purchase_order_id')->where('p.status', PaymentStatus::Active->value)
                ->whereNotIn('po.status', PurchaseOrderStatus::purchased())->pluck('pa.id'),
            'Requisition lines purchased or ordered beyond approval' => DB::table('requisition_items as ri')
                ->joinSub(RequisitionFulfilment::query(DB::table('purchase_order_item_requisitions')->select('requisition_item_id')), 'f', 'f.requisition_item_id', '=', 'ri.id')
                ->whereRaw('f.on_order + f.purchased > COALESCE(ri.approved_quantity, 0)')->pluck('ri.id'),
        ];

        $violations = array_filter(array_map(fn ($ids) => $ids->all(), $checks));
        foreach ($checks as $name => $ids) {
            $ids->isEmpty() ? $this->components->twoColumnDetail($name, '<fg=green>OK</>')
                : $this->components->twoColumnDetail($name, '<fg=red>'.$ids->count().' violation(s): '.$ids->take(10)->implode(', ').'</>');
        }
        if ($violations) {
            Log::critical('Data integrity check failed', $violations);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
