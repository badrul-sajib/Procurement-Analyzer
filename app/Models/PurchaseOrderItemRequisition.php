<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How much of a requisition line a PO line covers. */
#[Fillable(['purchase_order_item_id', 'requisition_item_id', 'ordered_quantity', 'purchased_quantity'])]
class PurchaseOrderItemRequisition extends Model
{
    protected function casts(): array
    {
        return ['ordered_quantity' => 'decimal:3', 'purchased_quantity' => 'decimal:3'];
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(RequisitionItem::class);
    }
}
