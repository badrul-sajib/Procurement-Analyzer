<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['purchase_order_id', 'item_id', 'ordered_quantity', 'purchased_quantity', 'unit_price',
    'discount_amount', 'tax_amount', 'notes'])]
class PurchaseOrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:3',
            'purchased_quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function requisitionAllocations(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemRequisition::class);
    }

    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryItem::class);
    }
}
