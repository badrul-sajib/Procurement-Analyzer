<?php

namespace Tests\Support;

use App\Actions\Purchases\ApprovePurchaseOrder;
use App\Actions\Purchases\MarkPurchaseOrderPurchased;
use App\Actions\Purchases\SavePurchaseOrder;
use App\Actions\Purchases\SubmitPurchaseOrder;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;

final class Purchasing
{
    /** @param  array<int, array{0: Item, 1: string|int, 2?: string|int}>  $lines  [item, qty, unit price] */
    public static function draft(Vendor $vendor, array $lines, string $orderDate = '2026-11-02'): PurchaseOrder
    {
        return app(SavePurchaseOrder::class)->handle([
            'vendor_id' => $vendor->id,
            'order_date' => $orderDate,
            'lines' => array_map(fn ($l) => ['item_id' => $l[0]->id, 'quantity' => (string) $l[1], 'unit_price' => (string) ($l[2] ?? 100)], $lines),
        ], null, self::user());
    }

    public static function approved(Vendor $vendor, array $lines): PurchaseOrder
    {
        $po = self::draft($vendor, $lines);
        app(SubmitPurchaseOrder::class)->handle($po, self::user());
        $po->refresh();
        if ($po->status->value === 'pending_approval') {
            app(ApprovePurchaseOrder::class)->handle($po, self::user());
        }

        return $po->fresh();
    }

    /** Approve and record the purchase. $bought: item id => purchased qty (default: all ordered). */
    public static function purchased(Vendor $vendor, array $lines, array $bought = [], string $date = '2026-11-03'): PurchaseOrder
    {
        $po = self::approved($vendor, $lines);
        app(MarkPurchaseOrderPurchased::class)->handle($po, [
            'purchase_date' => $date,
            'lines' => $po->items()->get()->map(fn ($l) => ['id' => $l->id, 'purchased_quantity' => (string) ($bought[$l->item_id] ?? $l->ordered_quantity)])->all(),
        ], self::user());

        return $po->fresh();
    }

    private static function user(): int
    {
        return (User::first() ?? User::factory()->create())->id;
    }
}
