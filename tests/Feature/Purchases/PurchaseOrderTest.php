<?php

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItemRequisition;
use App\Models\Vendor;
use App\Support\Settings;
use Tests\Support\Purchasing;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->paper = Item::factory()->unit('ream')->create(['name' => 'A4 Paper']);
    $this->pen = Item::factory()->unit('pcs')->create(['name' => 'Ball Pen']);
    $this->vendorA = Vendor::factory()->create(['name' => 'Vendor A']);
    $this->vendorB = Vendor::factory()->create(['name' => 'Vendor B']);
    $this->older = Scenario::requisition([[$this->paper, 10], [$this->pen, 50]], date: '2026-10-01');
    $this->newer = Scenario::requisition([[$this->paper, 20]], date: '2026-10-10');
});

function allocationsFor($requisition): array
{
    return PurchaseOrderItemRequisition::whereIn('requisition_item_id', $requisition->items->pluck('id'))
        ->get()->map(fn ($a) => [$a->ordered_quantity, $a->purchased_quantity])->all();
}

function analyzerRow(Item $item): array
{
    return collect(test()->getJson('/api/v1/analyzer/items')->json('data'))->firstWhere('item.id', $item->id);
}

it('creates a draft PO from pending quantity, filling the oldest requisition first', function () {
    actingWithPermissions(['purchases.create', 'purchases.view', 'finance.view']);

    $res = $this->postJson('/api/v1/purchase-orders', [
        'vendor_id' => $this->vendorA->id, 'order_date' => '2026-11-02', 'additional_charges' => '150.00',
        'lines' => [
            ['item_id' => $this->paper->id, 'quantity' => '25', 'unit_price' => '450.50', 'discount_amount' => '100', 'tax_amount' => '50.25'],
            ['item_id' => $this->pen->id, 'quantity' => '50', 'unit_price' => '10'],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.lines.0.line_total', '11212.75')  // 25 × 450.50 − 100 + 50.25
        ->assertJsonPath('data.subtotal', '11712.75')
        ->assertJsonPath('data.grand_total', '11862.75')
        ->assertJsonPath('data.payable_amount', '0.00');            // nothing is owed until purchased

    expect($res->json('data.po_number'))->toStartWith('PO-')
        ->and(collect($res->json('data.lines.0.requisitions'))->map(fn ($r) => [$r['requisition']['reference_no'], $r['ordered_quantity']])->all())
        ->toBe([[$this->older->reference_no, '10.000'], [$this->newer->reference_no, '15.000']])
        ->and(ActivityLog::where('action', 'purchase_order.created')->exists())->toBeTrue();
});

it('prevents purchasing more than the approved quantity still pending', function () {
    actingWithPermissions(['purchases.create']);
    Purchasing::draft($this->vendorA, [[$this->paper, 20]]);

    $this->postJson('/api/v1/purchase-orders', ['vendor_id' => $this->vendorB->id, 'order_date' => '2026-11-02',
        'lines' => [['item_id' => $this->paper->id, 'quantity' => '11', 'unit_price' => '1']]])
        ->assertUnprocessable()->assertJsonPath('code', 'over_purchase')
        ->assertJsonPath('errors', ['lines.0.quantity' => ['Only 10 is pending for this item; 11 requested.']]);
});

it('never purchases from unapproved requisitions or beyond a reduced approval', function () {
    actingWithPermissions(['purchases.create']);
    $verifiedOnly = Item::factory()->create();
    Scenario::requisition([[$verifiedOnly, 5]], 'verified');
    $reduced = Item::factory()->create();
    Scenario::requisition([[$reduced, 10]], approve: [0 => 4]);

    $post = fn (Item $item, $qty) => $this->postJson('/api/v1/purchase-orders', ['vendor_id' => $this->vendorA->id, 'order_date' => '2026-11-02',
        'lines' => [['item_id' => $item->id, 'quantity' => (string) $qty, 'unit_price' => '1']]]);
    $post($verifiedOnly, 1)->assertJsonPath('code', 'over_purchase');
    $post($reduced, 5)->assertJsonPath('code', 'over_purchase');
    $post($reduced, 4)->assertCreated();
});

it('buys one item from multiple vendors across several POs', function () {
    actingWithPermissions(['analyzer.view']);
    Purchasing::purchased($this->vendorA, [[$this->paper, 12]]);
    Purchasing::draft($this->vendorB, [[$this->paper, 18]]);

    expect(analyzerRow($this->paper))->toMatchArray(['approved' => '30.000', 'purchased' => '12.000', 'on_order' => '18.000', 'pending' => '0.000', 'purchase_status' => 'partially_purchased']);
});

it('records a full purchase, making the PO payable', function () {
    actingWithPermissions(['purchases.view', 'finance.view', 'analyzer.view']);
    $po = Purchasing::purchased($this->vendorA, [[$this->paper, 30, '500']]);

    $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk()
        ->assertJsonPath('data.status', 'purchased')->assertJsonPath('data.purchase_date', '2026-11-03')
        ->assertJsonPath('data.payable_amount', '15000.00')->assertJsonPath('data.payment_status', 'unpaid')
        ->assertJsonPath('data.delivery_status', 'not_delivered');
    expect(analyzerRow($this->paper))->toMatchArray(['purchased' => '30.000', 'pending' => '0.000', 'purchase_status' => 'purchased']);
});

it('releases a purchase shortfall back to pending from the newest requisition first', function () {
    actingWithPermissions(['analyzer.view']);
    $po = Purchasing::purchased($this->vendorA, [[$this->paper, 25]], bought: [$this->paper->id => 18]);

    expect($po->status->value)->toBe('partially_purchased')
        ->and(allocationsFor($this->older))->toBe([['10.000', '10.000']])   // oldest request stays fully covered
        ->and(allocationsFor($this->newer))->toBe([['15.000', '8.000']]);
    expect(analyzerRow($this->paper))->toMatchArray(['purchased' => '18.000', 'on_order' => '0.000', 'pending' => '12.000']);
});

it('validates actual purchase quantities and prices', function () {
    actingWithPermissions(['purchases.update']);
    $po = Purchasing::approved($this->vendorA, [[$this->paper, 5]]);
    $line = $po->items()->first();

    $this->postJson("/api/v1/purchase-orders/{$po->id}/mark-purchased", ['purchase_date' => '2026-11-03', 'lines' => [['id' => $line->id, 'purchased_quantity' => '6']]])
        ->assertJsonPath('code', 'invalid_purchase');
    $this->postJson("/api/v1/purchase-orders/{$po->id}/mark-purchased", ['purchase_date' => '2026-11-03', 'lines' => [['id' => $line->id, 'purchased_quantity' => '0']]])
        ->assertJsonPath('code', 'nothing_purchased');
    $this->postJson("/api/v1/purchase-orders/{$po->id}/mark-purchased", ['purchase_date' => '2026-11-03', 'lines' => [['id' => $line->id, 'purchased_quantity' => '5', 'unit_price' => '99.5']]])
        ->assertOk()->assertJsonPath('data.lines.0.line_total', '497.50');
});

it('follows the approval setting and requires approval permission', function () {
    Settings::put(['require_po_approval' => true], null);
    actingWithPermissions(['purchases.update', 'purchases.view']);
    $po = Purchasing::draft($this->vendorA, [[$this->paper, 5]]);

    $this->postJson("/api/v1/purchase-orders/{$po->id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_approval');
    $this->postJson("/api/v1/purchase-orders/{$po->id}/approve")->assertForbidden();
    actingWithPermissions(['purchases.approve', 'purchases.view']);
    $this->postJson("/api/v1/purchase-orders/{$po->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

    Settings::put(['require_po_approval' => false], null);
    actingWithPermissions(['purchases.update']);
    $po2 = Purchasing::draft($this->vendorA, [[$this->paper, 5]]);
    $this->postJson("/api/v1/purchase-orders/{$po2->id}/submit")->assertOk()->assertJsonPath('data.status', 'approved');
});

it('edits drafts by re-allocating, and locks submitted POs', function () {
    actingWithPermissions(['purchases.update', 'analyzer.view']);
    $po = Purchasing::draft($this->vendorA, [[$this->paper, 30]]);

    $this->putJson("/api/v1/purchase-orders/{$po->id}", ['lines' => [['item_id' => $this->paper->id, 'quantity' => '12', 'unit_price' => '1']]])->assertOk()
        ->assertJsonPath('data.ordered_quantity', '12.000');
    expect(analyzerRow($this->paper))->toMatchArray(['on_order' => '12.000', 'pending' => '18.000']);

    $this->postJson("/api/v1/purchase-orders/{$po->id}/submit")->assertOk();
    $this->putJson("/api/v1/purchase-orders/{$po->id}", ['lines' => [['item_id' => $this->paper->id, 'quantity' => '1', 'unit_price' => '1']]])
        ->assertJsonPath('code', 'invalid_status');
});

it('returns cancelled PO quantities to pending and excludes them from purchased totals', function () {
    actingWithPermissions(['purchases.cancel', 'analyzer.view', 'finance.view', 'purchases.view']);
    $po = Purchasing::purchased($this->vendorA, [[$this->paper, 30]]);

    $this->postJson("/api/v1/purchase-orders/{$po->id}/cancel", ['reason' => 'Vendor could not supply'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.payable_amount', '0.00');
    expect(analyzerRow($this->paper))->toMatchArray(['purchased' => '0.000', 'pending' => '30.000']);
    $this->postJson("/api/v1/purchase-orders/{$po->id}/cancel", ['reason' => 'again'])->assertJsonPath('code', 'invalid_status');
});

it('blocks cancelling a requisition that has active purchase orders', function () {
    actingWithPermissions(['requisitions.cancel']);
    Purchasing::draft($this->vendorA, [[$this->paper, 5]]);
    $this->postJson("/api/v1/requisitions/{$this->older->id}/cancel", ['reason' => 'No longer needed'])->assertJsonPath('code', 'requisition_has_purchases');
});

it('hides payable amounts from users without finance permission', function () {
    actingWithPermissions(['purchases.view']);
    $po = Purchasing::purchased($this->vendorA, [[$this->paper, 1]]);
    $res = $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk();
    expect($res->json('data'))->not->toHaveKey('payable_amount')->not->toHaveKey('paid_amount');
});

it('lists available pending items for the PO builder', function () {
    actingWithPermissions(['purchases.create']);
    Purchasing::draft($this->vendorA, [[$this->pen, 50]]); // pen fully on order

    $this->getJson('/api/v1/purchase-orders/available-items')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.item.id', $this->paper->id)->assertJsonPath('data.0.pending', '30.000');
    $lines = $this->getJson("/api/v1/purchase-orders/available-items/{$this->paper->id}")->assertOk()->json('data');
    expect(array_column($lines, 'pending'))->toBe(['10.000', '20.000']);
});

it('replays an idempotent PO submission instead of creating a second PO', function () {
    actingWithPermissions(['purchases.create']);
    $payload = ['vendor_id' => $this->vendorA->id, 'order_date' => '2026-11-02', 'lines' => [['item_id' => $this->paper->id, 'quantity' => '5', 'unit_price' => '1']]];
    $first = $this->withHeaders(['Idempotency-Key' => 'po-1'])->postJson('/api/v1/purchase-orders', $payload)->assertCreated()->json('data.id');
    $second = $this->withHeaders(['Idempotency-Key' => 'po-1'])->postJson('/api/v1/purchase-orders', $payload)->assertCreated()->json('data.id');
    expect($second)->toBe($first)->and(PurchaseOrder::count())->toBe(1);
});
