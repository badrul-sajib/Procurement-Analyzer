<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Purchased = 'purchased';
    case PartiallyPurchased = 'partially_purchased';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** Quantity is held against requisitions but not yet bought. */
    public static function onOrder(): array
    {
        return [self::Draft->value, self::PendingApproval->value, self::Approved->value];
    }

    /** Quantity is bought and the PO is a vendor payable. */
    public static function purchased(): array
    {
        return [self::Purchased->value, self::PartiallyPurchased->value, self::Completed->value];
    }

    public function isPurchased(): bool
    {
        return in_array($this->value, self::purchased(), true);
    }
}
