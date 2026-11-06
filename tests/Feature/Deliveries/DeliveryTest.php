<?php

use App\Models\ActivityLog;
use App\Models\Delivery;
use App\Models\Item;
use App\Models\Vendor;
use Tests\Support\Purchasing;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->paper = Item::factory()->unit('ream')->create(['name' => 'A4 Paper']);
    $this->older = Scenario::requisition([[$this->paper, 10]], date: '2026-10-01');
    $this->newer = Scenario::requisition([[$this->paper, 20]], date: '2026-10-10');
    $this->po = Purchasing::purchased(Vendor::factory()->create(), [[$this->paper, 30]]);
    $this->line = $this->po->items()->first();
});

function deliver(int $qty, bool $confirm = true, array $extra = [])
{
    return test()->postJson('/api/v1/deliveries', $extra + [
        'purchase_order_id' => test()->po->id, 'delivery_date' => '2026-11-05', 'delivery_reference' => 'CH-1', 'confirm' => $confirm,
        'lines' => [['purchase_order_item_id' => test()->line->id, 'quantity' => (string) $qty]],
    ]);
}

it('records partial deliveries and attributes them to the oldest requisition first', function () {
    actingWithPermissions(['deliveries.create', 'deliveries.confirm', 'deliveries.view', 'analyzer.view', 'purchases.view']);

    deliver(12)->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.total_quantity', '12.000');

    $this->getJson("/api/v1/purchase-orders/{$this->po->id}")->assertJsonPath('data.delivery_status', 'partially_delivered')
        ->assertJsonPath('data.lines.0.delivered_quantity', '12.000')->assertJsonPath('data.lines.0.remaining_delivery', '18.000');

    $sources = collect($this->getJson("/api/v1/analyzer/items/{$this->paper->id}/requisitions")->json('data'))->pluck('delivered')->all();
    expect($sources)->toBe(['10.000', '2.000']);
    $this->getJson('/api/v1/analyzer/items')->assertJsonPath('data.0.delivery_status', 'partially_delivered')
        ->assertJsonPath('data.0.remaining_delivery', '18.000');
});

it('completes after full delivery but not before', function () {
    actingWithPermissions(['deliveries.create', 'deliveries.confirm', 'purchases.update']);
    deliver(20)->assertCreated();
    $this->postJson("/api/v1/purchase-orders/{$this->po->id}/complete")->assertJsonPath('code', 'not_fully_delivered');

    deliver(10)->assertCreated();
    $this->postJson("/api/v1/purchase-orders/{$this->po->id}/complete")->assertOk()->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.delivery_status', 'fully_delivered');
});

it('prevents delivering more than was purchased, counting open deliveries', function () {
    actingWithPermissions(['deliveries.create', 'deliveries.confirm']);
    deliver(31)->assertUnprocessable()->assertJsonPath('code', 'over_delivery');

    deliver(25, confirm: false)->assertCreated()->assertJsonPath('data.status', 'pending');
    deliver(6)->assertUnprocessable()->assertJsonPath('errors', ['lines.0.quantity' => ['Only 5.000 is left to deliver.']]);
});

it('only counts confirmed deliveries and re-checks capacity at confirmation', function () {
    actingWithPermissions(['deliveries.create', 'deliveries.confirm', 'analyzer.view', 'deliveries.reverse']);
    $pendingId = deliver(20, confirm: false)->json('data.id');
    $this->getJson('/api/v1/analyzer/items')->assertJsonPath('data.0.delivered', '0.000');

    $this->postJson("/api/v1/deliveries/{$pendingId}/confirm")->assertOk();
    $this->getJson('/api/v1/analyzer/items')->assertJsonPath('data.0.delivered', '20.000');
    $this->postJson("/api/v1/deliveries/{$pendingId}/confirm")->assertJsonPath('code', 'invalid_status');
});

it('reverses deliveries auditably instead of deleting them', function () {
    actingWithPermissions(['deliveries.create', 'deliveries.confirm', 'deliveries.reverse', 'deliveries.view', 'analyzer.view']);
    $id = deliver(30)->json('data.id');

    $this->postJson("/api/v1/deliveries/{$id}/reverse", ['reason' => 'Wrong challan'])->assertOk()
        ->assertJsonPath('data.status', 'reversed')->assertJsonPath('data.reversal_reason', 'Wrong challan');
    expect(Delivery::find($id))->not->toBeNull()
        ->and(ActivityLog::where('action', 'delivery.reversed')->where('record_id', $id)->exists())->toBeTrue();
    $this->getJson('/api/v1/analyzer/items')->assertJsonPath('data.0.delivered', '0.000');

    deliver(30)->assertCreated(); // capacity is free again
});

it('blocks deliveries on draft orders and reversals once an order is completed', function () {
    actingWithPermissions(['deliveries.create', 'deliveries.confirm', 'deliveries.reverse', 'purchases.update']);
    $item = Item::factory()->create();
    Scenario::requisition([[$item, 5]]);
    $draft = Purchasing::draft(Vendor::factory()->create(), [[$item, 5]]);
    $this->postJson('/api/v1/deliveries', ['purchase_order_id' => $draft->id, 'delivery_date' => '2026-11-05',
        'lines' => [['purchase_order_item_id' => $draft->items()->first()->id, 'quantity' => '1']]])->assertJsonPath('code', 'po_not_deliverable');

    $id = deliver(30)->json('data.id');
    $this->postJson("/api/v1/purchase-orders/{$this->po->id}/complete")->assertOk();
    $this->postJson("/api/v1/deliveries/{$id}/reverse", ['reason' => 'late correction'])->assertJsonPath('code', 'po_completed');
});

it('separates recording from confirming deliveries', function () {
    actingWithPermissions(['deliveries.create']);
    deliver(5, confirm: true)->assertForbidden();
    $id = deliver(5, confirm: false)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/deliveries/{$id}/confirm")->assertForbidden();
});

it('blocks cancelling a purchase order that has deliveries', function () {
    actingWithPermissions(['deliveries.create', 'deliveries.confirm', 'purchases.cancel']);
    deliver(5)->assertCreated();
    $this->postJson("/api/v1/purchase-orders/{$this->po->id}/cancel", ['reason' => 'mistake'])->assertJsonPath('code', 'has_deliveries');
});

it('filters deliveries by date and status', function () {
    actingWithPermissions(['deliveries.create', 'deliveries.confirm', 'deliveries.view']);
    deliver(5);
    deliver(5, confirm: false, extra: ['delivery_date' => '2026-10-20']);

    $this->getJson('/api/v1/deliveries?period=custom&date_from=2026-11-01&date_to=2026-11-30')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/deliveries?status=pending')->assertJsonCount(1, 'data')->assertJsonPath('data.0.delivery_date', '2026-10-20');
});
