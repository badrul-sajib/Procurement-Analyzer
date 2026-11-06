<?php

namespace App\Reports;

use App\Enums\DeliveryStatus;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Confirmed deliveries, by delivery date. */
final class DeliveredQuantityReport extends Report
{
    public function title(): string
    {
        return 'Delivered quantity report';
    }

    public function dateBasis(array $filters): string
    {
        return 'Delivery date';
    }

    public function rules(): array
    {
        return ['vendor_id' => ['nullable', 'integer'], 'item_id' => ['nullable', 'integer'], 'purchase_order_id' => ['nullable', 'integer']];
    }

    public function columns(array $filters): array
    {
        return ['delivery_date' => ['Delivery date', self::DATE], 'delivery_number' => ['Delivery', self::TEXT], 'po_number' => ['Purchase order', self::TEXT],
            'vendor' => ['Vendor', self::TEXT], 'item' => ['Item', self::TEXT], 'unit' => ['Unit', self::TEXT], 'quantity' => ['Delivered qty', self::QTY],
            'delivery_reference' => ['Reference', self::TEXT], 'received_by' => ['Received by', self::TEXT]];
    }

    public function defaultSort(): string
    {
        return '-delivery_date';
    }

    public function totals(): array
    {
        return ['quantity'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        return DB::table('delivery_items as di')->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
            ->join('purchase_orders as po', 'po.id', '=', 'd.purchase_order_id')->join('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'di.purchase_order_item_id')->join('items as i', 'i.id', '=', 'poi.item_id')
            ->join('units as u', 'u.id', '=', 'i.default_unit_id')->leftJoin('users as rb', 'rb.id', '=', 'd.received_by')
            ->where('d.status', DeliveryStatus::Confirmed->value)
            ->when($range, fn ($q) => $q->whereBetween('d.delivery_date', $range->dates()))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('po.vendor_id', $v))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('poi.item_id', $v))
            ->when($filters['purchase_order_id'] ?? null, fn ($q, $v) => $q->where('po.id', $v))
            ->select('di.id', 'd.delivery_date', 'd.delivery_number', 'po.po_number', 'v.name as vendor', 'i.name as item', 'u.name as unit',
                'di.quantity', 'd.delivery_reference', 'rb.name as received_by');
    }
}
