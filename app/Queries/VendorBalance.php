<?php

namespace App\Queries;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Vendor money figures, always computed from transactions (never cached or stored):
 *   payable     = Σ grand total of purchased / partially purchased / completed POs
 *   paid        = Σ active payments
 *   allocated   = Σ allocations of active payments
 *   outstanding = payable − allocated
 *   credit      = paid − allocated (money paid but not yet allocated to a PO)
 */
final class VendorBalance
{
    /** Grand total per PO: Σ line_total + additional charges. */
    public static function poTotals(): Builder
    {
        return DB::table('purchase_orders as po')
            ->leftJoin('purchase_order_items as poi', 'poi.purchase_order_id', '=', 'po.id')
            ->groupBy('po.id', 'po.vendor_id', 'po.status', 'po.purchase_date', 'po.additional_charges')
            ->select('po.id', 'po.vendor_id', 'po.status', 'po.purchase_date')
            ->selectRaw('COALESCE(SUM(poi.line_total), 0) + po.additional_charges AS grand_total');
    }

    /** Allocated amount per PO from active payments. */
    public static function poPaid(?string $asOf = null): Builder
    {
        return DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('p.status', PaymentStatus::Active->value)
            ->when($asOf, fn ($q) => $q->where('p.payment_date', '<=', $asOf))
            ->groupBy('pa.purchase_order_id')
            ->select('pa.purchase_order_id')->selectRaw('SUM(pa.amount) AS paid');
    }

    /** Per-vendor balance rows; optionally only purchases/payments dated on or before $asOf. */
    public static function query(?string $asOf = null): Builder
    {
        $payable = DB::query()->fromSub(self::poTotals(), 't')
            ->whereIn('t.status', PurchaseOrderStatus::purchased())
            ->when($asOf, fn ($q) => $q->where('t.purchase_date', '<=', $asOf))
            ->groupBy('t.vendor_id')
            ->select('t.vendor_id')->selectRaw('SUM(t.grand_total) AS payable, COUNT(*) AS purchase_orders, MAX(t.purchase_date) AS last_purchase_date');

        $paid = DB::table('payments as p')->where('p.status', PaymentStatus::Active->value)
            ->when($asOf, fn ($q) => $q->where('p.payment_date', '<=', $asOf))
            ->groupBy('p.vendor_id')->select('p.vendor_id')->selectRaw('SUM(p.amount) AS paid');

        $allocated = DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('purchase_orders as po', 'po.id', '=', 'pa.purchase_order_id')
            ->where('p.status', PaymentStatus::Active->value)
            ->when($asOf, fn ($q) => $q->where('p.payment_date', '<=', $asOf)->where('po.purchase_date', '<=', $asOf))
            ->groupBy('p.vendor_id')->select('p.vendor_id')->selectRaw('SUM(pa.amount) AS allocated');

        return DB::table('vendors as v')
            ->leftJoinSub($payable, 'pb', 'pb.vendor_id', '=', 'v.id')
            ->leftJoinSub($paid, 'pd', 'pd.vendor_id', '=', 'v.id')
            ->leftJoinSub($allocated, 'al', 'al.vendor_id', '=', 'v.id')
            ->select('v.id', 'v.code', 'v.name')
            ->selectRaw('COALESCE(pb.payable, 0) AS payable, COALESCE(pd.paid, 0) AS paid, COALESCE(al.allocated, 0) AS allocated')
            ->selectRaw('COALESCE(pb.payable, 0) - COALESCE(al.allocated, 0) AS outstanding')
            ->selectRaw('COALESCE(pd.paid, 0) - COALESCE(al.allocated, 0) AS credit')
            ->selectRaw('COALESCE(pb.purchase_orders, 0) AS purchase_orders, pb.last_purchase_date');
    }

    public static function forVendor(int $vendorId): array
    {
        return self::shape(self::query()->where('v.id', $vendorId)->first());
    }

    public static function shape(object $row): array
    {
        $m = fn ($v) => Decimal::add($v, 0, Decimal::MONEY);

        return [
            'vendor' => ['id' => $row->id, 'code' => $row->code, 'name' => $row->name],
            'total_purchase_amount' => $m($row->payable),
            'total_paid_amount' => $m($row->paid),
            'allocated_amount' => $m($row->allocated),
            'outstanding_amount' => $m($row->outstanding),
            'unallocated_credit' => $m($row->credit),
            'purchase_order_count' => (int) $row->purchase_orders,
            'last_purchase_date' => $row->last_purchase_date,
            'payment_status' => match (true) {
                Decimal::cmp($row->payable, 0, 2) === 0 => null,
                Decimal::cmp($row->outstanding, 0, 2) <= 0 => 'paid',
                Decimal::cmp($row->allocated, 0, 2) > 0 => 'partially_paid',
                default => 'unpaid',
            },
        ];
    }

    /** Payable POs of a vendor with what is still due, for allocating payments. */
    public static function payables(int $vendorId): array
    {
        return DB::query()->fromSub(self::poTotals(), 't')
            ->join('purchase_orders as po', 'po.id', '=', 't.id')
            ->leftJoinSub(self::poPaid(), 'pp', 'pp.purchase_order_id', '=', 't.id')
            ->where('t.vendor_id', $vendorId)
            ->whereIn('t.status', PurchaseOrderStatus::purchased())
            ->whereRaw('t.grand_total - COALESCE(pp.paid, 0) > 0')
            ->orderBy('t.purchase_date')->orderBy('t.id')
            ->select('t.id', 'po.po_number', 't.purchase_date', 't.grand_total')
            ->selectRaw('COALESCE(pp.paid, 0) AS paid, t.grand_total - COALESCE(pp.paid, 0) AS due')
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'po_number' => $r->po_number, 'purchase_date' => $r->purchase_date,
                'grand_total' => Decimal::add($r->grand_total, 0, 2), 'paid' => Decimal::add($r->paid, 0, 2), 'due' => Decimal::add($r->due, 0, 2)])
            ->all();
    }
}
