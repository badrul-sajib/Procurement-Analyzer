<?php

namespace App\Queries;

use App\Enums\DeliveryStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\RequisitionStatus;
use App\Support\DataVersion;
use App\Support\DateRange;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard figures, each filtered on its own business date: requisition metrics on the
 * requisition date, purchases on the purchase date, deliveries on the delivery date, payments
 * on the payment date. Cached per data version, so nothing outlives the data behind it.
 */
final class Dashboard
{
    public function __construct(private ?DateRange $range) {}

    public function summary(bool $finance): array
    {
        $dates = $this->range?->dates();

        return DataVersion::remember('dashboard.summary', ['dates' => $dates, 'finance' => $finance], function () use ($dates, $finance) {
            $analyzer = (new Analyzer($dates ? ['dates' => $dates] : []))->summary();

            $purchased = DB::table('purchase_order_items as poi')->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
                ->whereIn('po.status', PurchaseOrderStatus::purchased())
                ->when($dates, fn ($q) => $q->whereBetween('po.purchase_date', $dates))
                ->sum('poi.purchased_quantity');
            $delivered = DB::table('delivery_items as di')->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
                ->where('d.status', DeliveryStatus::Confirmed->value)
                ->when($dates, fn ($q) => $q->whereBetween('d.delivery_date', $dates))
                ->sum('di.quantity');

            $summary = [
                'total_requisitions' => $analyzer['total_requisitions'],
                'unique_items' => $analyzer['unique_items'],
                'requisition_lines' => $analyzer['requisition_lines'],
                'requested_quantity' => $analyzer['requested_quantity'],
                'pending_purchase_quantity' => $analyzer['pending_quantity'],
                'purchased_quantity' => Decimal::add((string) $purchased, 0),
                'delivered_quantity' => Decimal::add((string) $delivered, 0),
            ];
            if (! $finance) {
                return $summary;
            }

            $amount = DB::query()->fromSub(VendorBalance::poTotals(), 't')->whereIn('t.status', PurchaseOrderStatus::purchased())
                ->when($dates, fn ($q) => $q->whereBetween('t.purchase_date', $dates))->sum('t.grand_total');
            $paid = DB::table('payments')->where('status', PaymentStatus::Active->value)
                ->when($dates, fn ($q) => $q->whereBetween('payment_date', $dates))->sum('amount');
            $outstanding = DB::query()->fromSub(VendorBalance::query($dates[1] ?? null), 'b')->sum('b.outstanding');

            return $summary + [
                'total_purchased_amount' => Decimal::add((string) $amount, 0, 2),
                'total_paid_amount' => Decimal::add((string) $paid, 0, 2),
                'total_outstanding_amount' => Decimal::add((string) $outstanding, 0, 2), // as of the end of the period
            ];
        });
    }

    public function charts(bool $finance): array
    {
        $dates = $this->range?->dates();

        return DataVersion::remember('dashboard.charts', ['dates' => $dates, 'finance' => $finance], function () use ($dates, $finance) {
            // Daily trend defaults to the last 30 days, monthly charts to the last 12 months.
            $daily = $dates ?? [now(config('app.business_timezone'))->subDays(29)->toDateString(), now(config('app.business_timezone'))->toDateString()];
            $monthly = $dates ?? [now(config('app.business_timezone'))->subMonths(11)->startOfMonth()->toDateString(), now(config('app.business_timezone'))->toDateString()];
            $confirmed = fn () => DB::table('requisitions')->whereIn('status', RequisitionStatus::confirmed());
            $series = fn ($q) => $q->get()->map(fn ($r) => (array) $r)->all();

            $charts = [
                'daily_requisitions' => $series($confirmed()->whereBetween('requisition_date', $daily)->groupBy('requisition_date')
                    ->selectRaw('requisition_date AS period, COUNT(*) AS requisitions')->orderBy('period')),
                'monthly_requisitions' => $series($confirmed()->whereBetween('requisition_date', $monthly)->groupByRaw("DATE_FORMAT(requisition_date, '%Y-%m')")
                    ->selectRaw("DATE_FORMAT(requisition_date, '%Y-%m') AS period, COUNT(*) AS requisitions")->orderBy('period')),
                'item_quantities' => DB::query()->fromSub((new Analyzer($dates ? ['dates' => $dates] : []))->items(), 'x')
                    ->orderByDesc('x.requested')->limit(10)->get(['x.name', 'x.unit', 'x.requested', 'x.purchased', 'x.delivered'])->map(fn ($r) => (array) $r)->all(),
                'fulfilment' => (fn ($s) => ['pending' => $s['pending_quantity'], 'on_order' => $s['on_order_quantity'], 'purchased' => $s['purchased_quantity'], 'delivered' => $s['delivered_quantity']])(
                    (new Analyzer($dates ? ['dates' => $dates] : []))->summary()),
            ];
            if (! $finance) {
                return $charts;
            }

            $po = fn () => DB::query()->fromSub(VendorBalance::poTotals(), 't')->whereIn('t.status', PurchaseOrderStatus::purchased());

            return $charts + [
                'monthly_purchase_amount' => $series($po()->whereBetween('t.purchase_date', $monthly)->groupByRaw("DATE_FORMAT(t.purchase_date, '%Y-%m')")
                    ->selectRaw("DATE_FORMAT(t.purchase_date, '%Y-%m') AS period, SUM(t.grand_total) AS amount")->orderBy('period')),
                'vendor_purchase_amount' => $series($po()->when($dates, fn ($q) => $q->whereBetween('t.purchase_date', $dates))
                    ->join('vendors as v', 'v.id', '=', 't.vendor_id')->groupBy('v.id', 'v.name')->selectRaw('v.name AS vendor, SUM(t.grand_total) AS amount')->orderByDesc('amount')->limit(10)),
                'vendor_outstanding' => $series(DB::query()->fromSub(VendorBalance::query($dates[1] ?? null), 'b')->where('b.outstanding', '>', 0)
                    ->selectRaw('b.name AS vendor, b.outstanding AS amount')->orderByDesc('amount')->limit(10)),
                'monthly_payments' => $series(DB::table('payments')->where('status', PaymentStatus::Active->value)->whereBetween('payment_date', $monthly)
                    ->groupByRaw("DATE_FORMAT(payment_date, '%Y-%m')")->selectRaw("DATE_FORMAT(payment_date, '%Y-%m') AS period, SUM(amount) AS amount")->orderBy('period')),
            ];
        });
    }
}
