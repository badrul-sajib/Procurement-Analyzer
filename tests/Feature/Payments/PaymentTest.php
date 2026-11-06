<?php

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\Payment;
use App\Models\Vendor;
use Tests\Support\Purchasing;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->item = Item::factory()->create();
    Scenario::requisition([[$this->item, 1000]]);
    $this->vendorA = Vendor::factory()->create(['name' => 'Vendor A']);
    $this->vendorB = Vendor::factory()->create(['name' => 'Vendor B']);
    $this->vendorC = Vendor::factory()->create(['name' => 'Vendor C']);
    // Spec example: A ৳50,000 (two POs), B ৳30,000, C ৳20,000.
    $this->a1 = Purchasing::purchased($this->vendorA, [[$this->item, 100, '300']]);
    $this->a2 = Purchasing::purchased($this->vendorA, [[$this->item, 100, '200']]);
    $this->b1 = Purchasing::purchased($this->vendorB, [[$this->item, 100, '300']]);
    $this->c1 = Purchasing::purchased($this->vendorC, [[$this->item, 100, '200']]);
    actingWithPermissions(['payments.create', 'payments.allocate', 'payments.view', 'payments.reverse', 'finance.view', 'purchases.view']);
});

function pay(Vendor $vendor, string $amount, array $allocations = [], array $headers = [])
{
    return test()->withHeaders($headers)->postJson('/api/v1/payments', [
        'vendor_id' => $vendor->id, 'payment_date' => '2026-11-06', 'amount' => $amount, 'method' => 'bank_transfer',
        'transaction_reference' => 'TRX-1', 'allocations' => $allocations,
    ]);
}

function balance(Vendor $vendor): array
{
    return test()->getJson("/api/v1/vendors/{$vendor->id}/balance")->assertOk()->json('data');
}

it('computes vendor outstanding as payables minus allocated payments (spec example)', function () {
    pay($this->vendorA, '30000', [['purchase_order_id' => $this->a1->id, 'amount' => '30000']])->assertCreated();
    pay($this->vendorB, '30000', [['purchase_order_id' => $this->b1->id, 'amount' => '30000']])->assertCreated();

    expect(balance($this->vendorA))->toMatchArray(['total_purchase_amount' => '50000.00', 'total_paid_amount' => '30000.00', 'outstanding_amount' => '20000.00', 'payment_status' => 'partially_paid'])
        ->and(balance($this->vendorB))->toMatchArray(['outstanding_amount' => '0.00', 'payment_status' => 'paid'])
        ->and(balance($this->vendorC))->toMatchArray(['total_paid_amount' => '0.00', 'outstanding_amount' => '20000.00', 'payment_status' => 'unpaid']);
});

it('marks POs unpaid, partially paid and paid from their allocations', function () {
    pay($this->vendorA, '25000', [['purchase_order_id' => $this->a1->id, 'amount' => '10000']])->assertCreated();
    $this->getJson("/api/v1/purchase-orders/{$this->a1->id}")->assertJsonPath('data.payment_status', 'partially_paid')->assertJsonPath('data.due_amount', '20000.00');

    $payment = Payment::sole();
    $this->postJson("/api/v1/payments/{$payment->id}/allocations", ['allocations' => [
        ['purchase_order_id' => $this->a1->id, 'amount' => '5000'], ['purchase_order_id' => $this->a2->id, 'amount' => '10000'],
    ]])->assertOk()->assertJsonPath('data.allocated_amount', '25000.00')->assertJsonPath('data.unallocated_amount', '0.00');

    $this->getJson("/api/v1/purchase-orders/{$this->a2->id}")->assertJsonPath('data.payment_status', 'partially_paid')->assertJsonPath('data.due_amount', '10000.00');
    $this->getJson("/api/v1/purchase-orders/{$this->a1->id}")->assertJsonPath('data.due_amount', '15000.00');

    pay($this->vendorA, '10000', [['purchase_order_id' => $this->a2->id, 'amount' => '10000']])->assertCreated();
    $this->getJson("/api/v1/purchase-orders/{$this->a2->id}")->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.due_amount', '0.00');
});

it('keeps unallocated money as vendor credit without reducing outstanding', function () {
    pay($this->vendorC, '5000')->assertCreated()->assertJsonPath('data.unallocated_amount', '5000.00');
    expect(balance($this->vendorC))->toMatchArray(['total_paid_amount' => '5000.00', 'outstanding_amount' => '20000.00', 'unallocated_credit' => '5000.00']);
});

it('prevents over-allocation of a payment and over-payment of a PO', function (Closure $allocations, string $field) {
    $res = pay($this->vendorA, '10000', $allocations($this))->assertUnprocessable()->assertJsonPath('code', 'over_allocation');
    expect(array_keys($res->json('errors')))->toContain($field)
        ->and(Payment::count())->toBe(0); // all-or-nothing: the payment is not half-recorded
})->with([
    'more than the payment' => [fn ($t) => [['purchase_order_id' => $t->a1->id, 'amount' => '6000'], ['purchase_order_id' => $t->a2->id, 'amount' => '5000']], 'allocations'],
    'more than the PO is due' => [fn ($t) => [['purchase_order_id' => $t->a2->id, 'amount' => '20000.01']], 'allocations.0.amount'],
    'another vendor\'s PO' => [fn ($t) => [['purchase_order_id' => $t->b1->id, 'amount' => '100']], 'allocations.0.purchase_order_id'],
]);

it('refuses allocations to purchase orders that are not purchased yet', function () {
    $draft = Purchasing::draft($this->vendorA, [[$this->item, 1]]);
    pay($this->vendorA, '100', [['purchase_order_id' => $draft->id, 'amount' => '100']])->assertJsonPath('code', 'over_allocation');
});

it('reverses payments auditably and recalculates balances', function () {
    $id = pay($this->vendorA, '30000', [['purchase_order_id' => $this->a1->id, 'amount' => '30000']])->json('data.id');

    $this->postJson("/api/v1/payments/{$id}/reverse", ['reason' => 'Cheque bounced'])->assertOk()->assertJsonPath('data.status', 'reversed');
    expect(balance($this->vendorA))->toMatchArray(['total_paid_amount' => '0.00', 'outstanding_amount' => '50000.00'])
        ->and(ActivityLog::where('action', 'payment.reversed')->exists())->toBeTrue();
    $this->getJson("/api/v1/purchase-orders/{$this->a1->id}")->assertJsonPath('data.payment_status', 'unpaid');
    $this->postJson("/api/v1/payments/{$id}/allocations", ['allocations' => [['purchase_order_id' => $this->a1->id, 'amount' => '1']]])->assertJsonPath('code', 'payment_reversed');
    $this->postJson("/api/v1/payments/{$id}/reverse", ['reason' => 'again'])->assertJsonPath('code', 'invalid_status');
});

it('blocks cancelling a PO that has allocated payments until they are reversed', function () {
    actingWithPermissions(['payments.create', 'payments.allocate', 'payments.reverse', 'purchases.cancel']);
    $id = pay($this->vendorC, '100', [['purchase_order_id' => $this->c1->id, 'amount' => '100']])->json('data.id');
    $this->postJson("/api/v1/purchase-orders/{$this->c1->id}/cancel", ['reason' => 'duplicate'])->assertJsonPath('code', 'has_payments');

    $this->postJson("/api/v1/payments/{$id}/reverse", ['reason' => 'undo'])->assertOk();
    $this->postJson("/api/v1/purchase-orders/{$this->c1->id}/cancel", ['reason' => 'duplicate'])->assertOk();
});

it('records a payment only once per idempotency key', function () {
    $headers = ['Idempotency-Key' => 'pay-123'];
    $first = pay($this->vendorB, '1000', [], $headers)->assertCreated()->json('data.id');
    $again = pay($this->vendorB, '1000', [], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->json('data.id');
    expect($again)->toBe($first)->and(Payment::count())->toBe(1);
});

it('separates recording, allocating and reversing permissions', function () {
    actingWithPermissions(['payments.create']);
    pay($this->vendorA, '100', [['purchase_order_id' => $this->a1->id, 'amount' => '100']])->assertForbidden();
    $id = pay($this->vendorA, '100')->assertCreated()->json('data.id');
    $this->postJson("/api/v1/payments/{$id}/allocations", ['allocations' => [['purchase_order_id' => $this->a1->id, 'amount' => '100']]])->assertForbidden();
    $this->postJson("/api/v1/payments/{$id}/reverse", ['reason' => 'x x x'])->assertForbidden();
    $this->getJson("/api/v1/vendors/{$this->vendorA->id}/balance")->assertForbidden();
});

it('lists a vendor\'s payable POs with what is still due, oldest first', function () {
    pay($this->vendorA, '30000', [['purchase_order_id' => $this->a1->id, 'amount' => '30000']]);
    $this->getJson("/api/v1/vendors/{$this->vendorA->id}/payables")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.po_number', $this->a2->po_number)->assertJsonPath('data.0.due', '20000.00');
});

it('rejects float-like and negative amounts', function () {
    pay($this->vendorA, '10.005')->assertUnprocessable()->assertJsonValidationErrors('amount');
    pay($this->vendorA, '-5')->assertUnprocessable()->assertJsonValidationErrors('amount');
});

it('shows vendor price history and purchased items', function () {
    actingWithPermissions(['vendors.view', 'purchases.view']);
    $this->getJson("/api/v1/vendors/{$this->vendorA->id}/items")->assertOk()
        ->assertJsonPath('data.0.total_purchased', '200.000')->assertJsonCount(2, 'data.0.history');
    $this->getJson("/api/v1/items/{$this->item->id}/purchase-history")->assertOk()->assertJsonCount(4, 'data');
});
