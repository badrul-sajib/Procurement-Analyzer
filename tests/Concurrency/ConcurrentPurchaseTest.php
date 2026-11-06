<?php

use App\Actions\Purchases\AllocateRequisitionLines;
use App\Actions\Purchases\SavePurchaseOrder;
use App\Models\Item;
use App\Models\PurchaseOrderItemRequisition;
use App\Models\User;
use App\Models\Vendor;
use Tests\Concurrency\Concurrent;
use Tests\Support\Scenario;

afterEach(fn () => AllocateRequisitionLines::$afterLock = null);

it('never lets two concurrent purchase orders exceed the approved quantity', function () {
    $item = Item::factory()->create();
    Scenario::requisition([[$item, 10]]);
    $vendor = Vendor::factory()->create();
    $userId = User::first()->id;
    $order = fn () => app(SavePurchaseOrder::class)->handle([
        'vendor_id' => $vendor->id, 'order_date' => '2026-11-02',
        'lines' => [['item_id' => $item->id, 'quantity' => '7', 'unit_price' => '1']],
    ], null, $userId)->id;

    [$child, $parent] = Concurrent::run(
        child: function () use ($order) {
            AllocateRequisitionLines::$afterLock = fn () => usleep(800_000); // hold the lock while the parent arrives

            return $order();
        },
        parent: $order,
    );

    expect($child['ok'])->toBeTrue()
        ->and($parent)->toMatchArray(['ok' => false, 'code' => 'over_purchase'])
        ->and(PurchaseOrderItemRequisition::sum('ordered_quantity'))->toEqual(7);
});
