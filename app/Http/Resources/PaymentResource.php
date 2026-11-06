<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $allocated = Decimal::add($this->allocated_amount ?? 0, 0, Decimal::MONEY);

        return [
            'id' => $this->id,
            'payment_reference' => $this->payment_reference,
            'vendor' => $this->whenLoaded('vendor', fn () => ['id' => $this->vendor->id, 'code' => $this->vendor->code, 'name' => $this->vendor->name]),
            'payment_date' => $this->payment_date?->toDateString(),
            'amount' => $this->amount,
            'allocated_amount' => $allocated,
            'unallocated_amount' => Decimal::sub($this->amount, $allocated, Decimal::MONEY),
            'method' => $this->method->value,
            'transaction_reference' => $this->transaction_reference,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'created_by' => $this->whenLoaded('creator', fn () => ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'created_at' => $this->created_at?->toIso8601String(),
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a) => [
                'id' => $a->id, 'amount' => $a->amount, 'created_at' => $a->created_at?->toIso8601String(),
                'purchase_order' => ['id' => $a->purchaseOrder->id, 'po_number' => $a->purchaseOrder->po_number, 'purchase_date' => $a->purchaseOrder->purchase_date?->toDateString()],
            ])),
        ];
    }
}
