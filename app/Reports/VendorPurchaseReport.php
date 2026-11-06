<?php

namespace App\Reports;

use App\Enums\PurchaseOrderStatus;
use App\Queries\VendorBalance;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VendorPurchaseReport extends Report
{
    public function title(): string
    {
        return 'Vendor-wise purchase report';
    }

    public function dateBasis(array $filters): string
    {
        return 'Purchase date';
    }

    public function permission(): string
    {
        return 'reports.financial';
    }

    public function columns(array $filters): array
    {
        return ['code' => ['Code', self::TEXT], 'vendor' => ['Vendor', self::TEXT], 'purchase_orders' => ['POs', self::INT],
            'items' => ['Items', self::INT], 'amount' => ['Purchase amount', self::MONEY], 'last_purchase_date' => ['Last purchase', self::DATE]];
    }

    public function defaultSort(): string
    {
        return '-amount';
    }

    public function totals(): array
    {
        return ['purchase_orders', 'amount'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        $items = DB::table('purchase_order_items')->groupBy('purchase_order_id')->select('purchase_order_id')->selectRaw('COUNT(DISTINCT item_id) AS n');

        return DB::query()->fromSub(VendorBalance::poTotals(), 't')->join('vendors as v', 'v.id', '=', 't.vendor_id')
            ->leftJoinSub($items, 'it', 'it.purchase_order_id', '=', 't.id')
            ->whereIn('t.status', PurchaseOrderStatus::purchased())
            ->when($range, fn ($q) => $q->whereBetween('t.purchase_date', $range->dates()))
            ->groupBy('v.id', 'v.code', 'v.name')
            ->select('v.id', 'v.code', 'v.name as vendor')
            ->selectRaw('COUNT(*) AS purchase_orders, SUM(COALESCE(it.n, 0)) AS items, SUM(t.grand_total) AS amount, MAX(t.purchase_date) AS last_purchase_date');
    }
}
