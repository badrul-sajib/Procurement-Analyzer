<?php

namespace App\Queries;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Vendor-specific purchase prices, read straight from purchased PO lines (latest first). */
final class PriceHistory
{
    public static function query(): Builder
    {
        return DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->join('items as i', 'i.id', '=', 'poi.item_id')
            ->join('units as u', 'u.id', '=', 'i.default_unit_id')
            ->join('vendors as v', 'v.id', '=', 'po.vendor_id')
            ->whereIn('po.status', PurchaseOrderStatus::purchased())
            ->where('poi.purchased_quantity', '>', 0)
            ->orderByDesc('po.purchase_date')->orderByDesc('po.id')
            ->select('poi.item_id', 'i.code as item_code', 'i.name as item_name', 'u.name as unit', 'po.id as purchase_order_id', 'po.po_number',
                'po.purchase_date', 'po.vendor_id', 'v.name as vendor_name', 'poi.purchased_quantity as quantity', 'poi.unit_price');
    }

    public static function shape(object $r): array
    {
        return [
            'purchase_order' => ['id' => $r->purchase_order_id, 'po_number' => $r->po_number],
            'vendor' => ['id' => $r->vendor_id, 'name' => $r->vendor_name],
            'item' => ['id' => $r->item_id, 'name' => $r->item_name],
            'purchase_date' => $r->purchase_date,
            'quantity' => $r->quantity,
            'unit_price' => $r->unit_price,
        ];
    }
}
