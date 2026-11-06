<?php

namespace App\Http\Resources;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Delivery */
class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;

        return [
            'id' => $this->id,
            'delivery_number' => $this->delivery_number,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder->id, 'po_number' => $this->purchaseOrder->po_number,
                'vendor' => $this->purchaseOrder->relationLoaded('vendor') ? ['id' => $this->purchaseOrder->vendor->id, 'name' => $this->purchaseOrder->vendor->name] : null,
            ]),
            'delivery_date' => $this->delivery_date?->toDateString(),
            'delivery_reference' => $this->delivery_reference,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'total_quantity' => $this->whenHas('total_quantity', fn () => bcadd((string) $this->total_quantity, '0', 3)),
            'received_by' => $this->whenLoaded('receiver', fn () => $person($this->receiver)),
            'created_by' => $this->whenLoaded('creator', fn () => $person($this->creator)),
            'created_at' => $this->created_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'lines' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'purchase_order_item_id' => $line->purchase_order_item_id,
                'item' => $line->relationLoaded('purchaseOrderItem') ? ['id' => $line->purchaseOrderItem->item->id, 'name' => $line->purchaseOrderItem->item->name, 'unit' => $line->purchaseOrderItem->item->defaultUnit->name] : null,
                'purchased_quantity' => $line->relationLoaded('purchaseOrderItem') ? $line->purchaseOrderItem->purchased_quantity : null,
                'quantity' => $line->quantity,
                'notes' => $line->notes,
            ])),
        ];
    }
}
