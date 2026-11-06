<?php

use App\Actions\Payments\AllocatePayment;
use App\Actions\Payments\RecordPayment;
use App\Models\Item;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\Vendor;
use Tests\Concurrency\Concurrent;
use Tests\Support\Purchasing;
use Tests\Support\Scenario;

it('never lets two concurrent allocations pay a PO beyond its total', function () {
    $item = Item::factory()->create();
    Scenario::requisition([[$item, 10]]);
    $vendor = Vendor::factory()->create();
    $po = Purchasing::purchased($vendor, [[$item, 10, '100']]); // ৳1,000 due
    $userId = User::first()->id;
    $record = fn () => app(RecordPayment::class)->handle(['vendor_id' => $vendor->id, 'payment_date' => '2026-11-06', 'amount' => '700', 'method' => 'cash'], $userId);
    [$p1, $p2] = [$record(), $record()];
    $allocate = fn ($payment) => fn () => app(AllocatePayment::class)->handle($payment, [['purchase_order_id' => $po->id, 'amount' => '700']], $userId)->id;

    // Child holds the PO lock (slow statement inside the transaction) while the parent arrives.
    [$child, $parent] = Concurrent::run(child: function () use ($allocate, $p1) {
        DB::beforeExecuting(function (string $sql) {
            if (str_contains($sql, 'insert into `payment_allocations`')) {
                usleep(800_000);
            }
        });

        return $allocate($p1)();
    }, parent: $allocate($p2));

    expect([$child['ok'], $parent['ok']])->toBe([true, false])
        ->and($parent['code'])->toBe('over_allocation')
        ->and(PaymentAllocation::sum('amount'))->toEqual(700);
});
