<?php

namespace App\Actions\Requisitions;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Requisition;
use Illuminate\Support\Facades\DB;

final class RequisitionGuard
{
    /** Re-read the row under a lock so concurrent workflow actions serialize. */
    public static function lock(Requisition $requisition): Requisition
    {
        return Requisition::lockForUpdate()->findOrFail($requisition->id);
    }

    public static function assertStatus(Requisition $requisition, array $allowed, string $action): void
    {
        if (! in_array($requisition->status, $allowed, true)) {
            throw new BusinessRuleException('invalid_status', sprintf(
                'Cannot %s a requisition that is %s.', $action, str_replace('_', ' ', $requisition->status->value),
            ));
        }
    }

    /** True when any line is on a purchase order that is not cancelled. */
    public static function hasActivePurchases(Requisition $requisition): bool
    {
        return DB::table('purchase_order_item_requisitions as a')
            ->join('requisition_items as ri', 'ri.id', '=', 'a.requisition_item_id')
            ->join('purchase_order_items as poi', 'poi.id', '=', 'a.purchase_order_item_id')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->where('ri.requisition_id', $requisition->id)
            ->where('po.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->exists();
    }
}
