<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['vendor_id', 'order_date', 'purchase_date', 'status', 'additional_charges', 'notes', 'created_by'])]
class PurchaseOrder extends Model
{
    protected function casts(): array
    {
        $casts = [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date:Y-m-d',
            'purchase_date' => 'date:Y-m-d',
            'additional_charges' => 'decimal:2',
        ];
        foreach (['submitted', 'approved', 'purchased', 'completed', 'cancelled'] as $event) {
            $casts["{$event}_at"] = 'datetime';
        }

        return $casts;
    }

    /**
     * Money and quantity totals computed from lines, deliveries and payments (never stored):
     * subtotal, paid_amount, ordered/purchased/delivered quantities.
     */
    public function scopeWithTotals(Builder $query): Builder
    {
        return $query
            ->withSum('items as subtotal', 'line_total')
            ->withSum('items as ordered_total_quantity', 'ordered_quantity')
            ->withSum('items as purchased_total_quantity', 'purchased_quantity')
            ->addSelect([
                'paid_amount' => DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
                    ->whereColumn('pa.purchase_order_id', 'purchase_orders.id')->where('p.status', PaymentStatus::Active->value)
                    ->selectRaw('COALESCE(SUM(pa.amount), 0)'),
                'delivered_total_quantity' => DB::table('delivery_items as di')->join('deliveries as d', 'd.id', '=', 'di.delivery_id')
                    ->join('purchase_order_items as poi', 'poi.id', '=', 'di.purchase_order_item_id')
                    ->whereColumn('poi.purchase_order_id', 'purchase_orders.id')->where('d.status', DeliveryStatus::Confirmed->value)
                    ->selectRaw('COALESCE(SUM(di.quantity), 0)'),
            ]);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
