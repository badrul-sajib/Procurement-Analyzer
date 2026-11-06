<?php

namespace App\Reports;

use App\Enums\PurchaseOrderStatus;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Purchased lines, by the purchase (bill) date. */
final class PurchasedQuantityReport extends Report
{
    public function title(): string
    {
        return 'Purchased quantity report';
    }

    public function dateBasis(array $filters): string
    {
        return 'Purchase date';
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function rules(): array
    {
        return ['vendor_id' => ['nullable', 'integer'], 'item_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer']];
    }

    public function columns(array $filters): array
    {
        return ['code' => ['Code', self::TEXT], 'name' => ['Item', self::TEXT], 'unit' => ['Unit', self::TEXT], 'purchase_orders' => ['POs', self::INT],
            'vendors' => ['Vendors', self::INT], 'purchased_quantity' => ['Purchased qty', self::QTY], 'average_price' => ['Avg. unit price', self::MONEY],
            'amount' => ['Amount', self::MONEY]];
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function totals(): array
    {
        return ['purchased_quantity', 'amount'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        return DB::table('purchase_order_items as poi')->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->join('items as i', 'i.id', '=', 'poi.item_id')->join('units as u', 'u.id', '=', 'i.default_unit_id')
            ->whereIn('po.status', PurchaseOrderStatus::purchased())->where('poi.purchased_quantity', '>', 0)
            ->when($range, fn ($q) => $q->whereBetween('po.purchase_date', $range->dates()))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('po.vendor_id', $v))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('poi.item_id', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('i.category_id', $v))
            ->groupBy('i.id', 'i.code', 'i.name', 'u.name')
            ->select('i.id', 'i.code', 'i.name', 'u.name as unit')
            ->selectRaw('COUNT(DISTINCT po.id) AS purchase_orders, COUNT(DISTINCT po.vendor_id) AS vendors')
            ->selectRaw('SUM(poi.purchased_quantity) AS purchased_quantity, SUM(poi.line_total) AS amount')
            ->selectRaw('ROUND(SUM(poi.line_total) / NULLIF(SUM(poi.purchased_quantity), 0), 2) AS average_price');
    }
}
