<?php

namespace App\Http\Resources;

use App\Models\PurchaseOrder;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = fn (?int $id) => $id && isset($this->people[$id]) ? ['id' => $id, 'name' => $this->people[$id]] : null;
        $subtotal = Decimal::add($this->subtotal ?? 0, 0, Decimal::MONEY);
        $grand = Decimal::add($subtotal, $this->additional_charges, Decimal::MONEY);
        $payable = $this->status->isPurchased() ? $grand : '0.00';
        $paid = Decimal::add($this->paid_amount ?? 0, 0, Decimal::MONEY);
        $purchased = Decimal::add($this->purchased_total_quantity ?? 0, 0);
        $delivered = Decimal::add($this->delivered_total_quantity ?? 0, 0);
        $finance = $request->user()?->can('finance.view');

        return [
            'id' => $this->id,
            'po_number' => $this->po_number,
            'vendor' => $this->whenLoaded('vendor', fn () => ['id' => $this->vendor->id, 'code' => $this->vendor->code, 'name' => $this->vendor->name]),
            'order_date' => $this->order_date?->toDateString(),
            'purchase_date' => $this->purchase_date?->toDateString(),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'additional_charges' => $this->additional_charges,
            'subtotal' => $subtotal,
            'grand_total' => $grand,
            'ordered_quantity' => Decimal::add($this->ordered_total_quantity ?? 0, 0),
            'purchased_quantity' => $purchased,
            'delivered_quantity' => $delivered,
            'delivery_status' => match (true) {
                ! $this->status->isPurchased() || Decimal::cmp($delivered, '0') === 0 => 'not_delivered',
                Decimal::cmp($delivered, $purchased) >= 0 => 'fully_delivered',
                default => 'partially_delivered',
            },
            // Amounts owed and paid are finance data.
            'payable_amount' => $this->when($finance, $payable),
            'paid_amount' => $this->when($finance, $paid),
            'due_amount' => $this->when($finance, fn () => Decimal::sub($payable, $paid, Decimal::MONEY)),
            'payment_status' => $this->when($finance, fn () => match (true) {
                ! $this->status->isPurchased() => null,
                Decimal::cmp($paid, '0', Decimal::MONEY) === 0 => 'unpaid',
                Decimal::cmp($paid, $payable, Decimal::MONEY) >= 0 => 'paid',
                default => 'partially_paid',
            }),
            'created_by' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'created_at' => $this->created_at?->toIso8601String(),
            'events' => $this->when(isset($this->people), fn () => collect(['submitted', 'approved', 'purchased', 'completed', 'cancelled'])
                ->filter(fn ($e) => $this->{"{$e}_at"})
                ->map(fn ($e) => ['event' => $e, 'at' => $this->{"{$e}_at"}->toIso8601String(), 'by' => $person($this->{"{$e}_by"})])->values()),
            'cancellation_reason' => $this->cancellation_reason,
            'lines' => $this->whenLoaded('items', fn () => $this->items->map(fn ($line) => [
                'id' => $line->id,
                'item' => ['id' => $line->item->id, 'code' => $line->item->code, 'name' => $line->item->name, 'unit' => $line->item->defaultUnit->name],
                'ordered_quantity' => $line->ordered_quantity,
                'purchased_quantity' => $line->purchased_quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'tax_amount' => $line->tax_amount,
                'line_total' => $line->line_total,
                'delivered_quantity' => Decimal::add($line->delivered_quantity ?? 0, 0),
                'remaining_delivery' => Decimal::sub($line->purchased_quantity ?? 0, $line->delivered_quantity ?? 0),
                'notes' => $line->notes,
                'requisitions' => $line->requisitionAllocations->map(fn ($a) => [
                    'requisition_item_id' => $a->requisition_item_id,
                    'requisition' => ['id' => $a->requisitionItem->requisition->id, 'reference_no' => $a->requisitionItem->requisition->reference_no,
                        'requisition_date' => $a->requisitionItem->requisition->requisition_date?->toDateString()],
                    'ordered_quantity' => $a->ordered_quantity,
                    'purchased_quantity' => $a->purchased_quantity,
                ]),
            ])),
        ];
    }
}
