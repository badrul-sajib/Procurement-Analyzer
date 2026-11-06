<?php

namespace App\Actions\Purchases;

use App\Enums\DeliveryStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderGuard
{
    public static function lock(PurchaseOrder $po): PurchaseOrder
    {
        return PurchaseOrder::lockForUpdate()->findOrFail($po->id);
    }

    public static function assertStatus(PurchaseOrder $po, array $allowed, string $action): void
    {
        if (! in_array($po->status, $allowed, true)) {
            throw new BusinessRuleException('invalid_status', sprintf('Cannot %s a purchase order that is %s.', $action, str_replace('_', ' ', $po->status->value)));
        }
    }

    public static function hasDeliveries(PurchaseOrder $po): bool
    {
        return $po->deliveries()->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Confirmed->value])->exists();
    }

    public static function hasActivePayments(PurchaseOrder $po): bool
    {
        return DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('pa.purchase_order_id', $po->id)->where('p.status', PaymentStatus::Active->value)->exists();
    }
}
