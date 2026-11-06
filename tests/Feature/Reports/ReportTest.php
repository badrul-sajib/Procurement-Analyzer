<?php

use App\Actions\Deliveries\CreateDelivery;
use App\Actions\Payments\RecordPayment;
use App\Models\Item;
use App\Models\ReportExport;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Purchasing;
use Tests\Support\Scenario;

beforeEach(function () {
    Storage::fake('local');
    $this->paper = Item::factory()->unit('ream')->create(['name' => 'A4 Paper']);
    $this->pen = Item::factory()->unit('pcs')->create(['name' => '=HYPERLINK("http://evil")']); // formula-injection probe
    Scenario::requisition([[$this->paper, 10], [$this->pen, 5]], date: '2026-10-05');
    Scenario::requisition([[$this->paper, 20]], date: '2026-11-02');
    $this->vendor = Vendor::factory()->create(['name' => 'Vendor A']);
    // Ordered/purchased in different months on purpose: each report must use its own event date.
    $this->october = Purchasing::purchased($this->vendor, [[$this->paper, 10, '400']], date: '2026-10-20');
    $this->november = Purchasing::purchased($this->vendor, [[$this->paper, 20, '500'], [$this->pen, 5, '10']], date: '2026-11-03');
    $uid = User::first()->id;
    app(CreateDelivery::class)->handle(['purchase_order_id' => $this->october->id, 'delivery_date' => '2026-11-04',
        'lines' => [['purchase_order_item_id' => $this->october->items()->first()->id, 'quantity' => '10']]], $uid, true);
    app(RecordPayment::class)->handle(['vendor_id' => $this->vendor->id, 'payment_date' => '2026-10-25', 'amount' => '4000', 'method' => 'cash',
        'allocations' => [['purchase_order_id' => $this->october->id, 'amount' => '4000']]], $uid);
    app(RecordPayment::class)->handle(['vendor_id' => $this->vendor->id, 'payment_date' => '2026-11-10', 'amount' => '1000', 'method' => 'cheque',
        'allocations' => [['purchase_order_id' => $this->november->id, 'amount' => '1000']]], $uid);
    actingWithPermissions(['reports.view', 'reports.financial', 'reports.export', 'dashboard.view', 'finance.view']);
});

const NOV = '&period=custom&date_from=2026-11-01&date_to=2026-11-30';

function reportJson(string $key, string $query = ''): array
{
    return test()->getJson("/api/v1/reports/{$key}?per_page=100{$query}")->assertOk()->json();
}

it('filters each report on its own business event date', function () {
    expect(reportJson('requisitions', NOV)['meta']['total'])->toBe(1)                                   // requisition date
        ->and(reportJson('purchase-amount', NOV.'&group_by=month')['data'])->toBe([['period' => '2026-11', 'purchase_orders' => 1, 'vendors' => 1, 'amount' => '10050.00']])
        ->and(collect(reportJson('deliveries', NOV)['data'])->pluck('quantity')->all())->toBe(['10.000']) // delivered in Nov from an October PO
        ->and(reportJson('vendor-payments', NOV)['totals'])->toMatchArray(['amount' => '1000.00'])         // payment date
        ->and(reportJson('requisitions', '&date_basis=uploaded_at&period=today')['meta']['total'])->toBe(2);
});

it('aggregates items and purchased quantities correctly', function () {
    $items = collect(reportJson('items')['data'])->keyBy('name');
    expect($items['A4 Paper'])->toMatchArray(['requested' => '30.000', 'purchased' => '30.000', 'delivered' => '10.000', 'pending' => '0.000']);

    $purchased = collect(reportJson('purchased-quantity', NOV)['data'])->keyBy('name');
    expect($purchased['A4 Paper'])->toMatchArray(['purchased_quantity' => '20.000', 'amount' => '10000.00', 'average_price' => '500.00']);
});

it('reports outstanding balances as of the end of the period', function () {
    // End of October: owed 4,000 for the October PO, paid 4,000. The November PO does not exist yet.
    expect(reportJson('outstanding', '&period=custom&date_from=2026-10-01&date_to=2026-10-31')['data'][0])
        ->toMatchArray(['payable' => '4000.00', 'allocated' => '4000.00', 'outstanding' => '0.00'])
        ->and(reportJson('outstanding')['data'][0])->toMatchArray(['payable' => '14050.00', 'allocated' => '5000.00', 'outstanding' => '9050.00']);
});

it('summarises totals over the full filtered set, not just the page', function () {
    $res = test()->getJson('/api/v1/reports/purchased-quantity?per_page=1')->assertOk();
    expect($res->json('meta.total'))->toBe(2)->and($res->json('totals.amount'))->toBe('14050.00');
});

it('requires financial permission for financial reports', function () {
    actingWithPermissions(['reports.view']);
    test()->getJson('/api/v1/reports/vendor-payments')->assertForbidden();
    test()->getJson('/api/v1/reports/items')->assertOk();
    expect(collect(test()->getJson('/api/v1/reports')->json('data'))->pluck('key'))->not->toContain('outstanding');
});

it('exports CSV with the current filters and escapes spreadsheet formulas', function () {
    $id = test()->postJson('/api/v1/reports/items/exports', ['format' => 'csv', 'filters' => ['period' => 'custom', 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']])
        ->assertAccepted()->json('data.id');

    $export = ReportExport::find($id);
    expect($export->status->value)->toBe('completed')->and($export->row_count)->toBe(2);
    $csv = Storage::disk('local')->get($export->file_path);
    expect($csv)->toContain('"A4 Paper"')->toContain("\"'=HYPERLINK")->toContain('"10.000"');

    $res = test()->get("/api/v1/exports/{$id}/download")->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('items-');
});

it('exports Excel and PDF', function (string $format) {
    $id = test()->postJson('/api/v1/reports/outstanding/exports', ['format' => $format])->assertAccepted()->json('data.id');
    $export = ReportExport::find($id);
    expect($export->status->value)->toBe('completed');
    Storage::disk('local')->assertExists($export->file_path);
    expect(Storage::disk('local')->size($export->file_path))->toBeGreaterThan(500);
})->with(['xlsx', 'pdf']);

it('never lets another user see or download an export', function () {
    $id = test()->postJson('/api/v1/reports/items/exports', ['format' => 'csv'])->json('data.id');
    actingWithPermissions(['reports.view', 'reports.export']);
    test()->getJson("/api/v1/exports/{$id}")->assertNotFound();
    test()->get("/api/v1/exports/{$id}/download")->assertNotFound();
});

it('validates export filters before queueing', function () {
    test()->postJson('/api/v1/reports/items/exports', ['format' => 'docx'])->assertUnprocessable();
    test()->postJson('/api/v1/reports/items/exports', ['format' => 'csv', 'filters' => ['period' => 'custom']])->assertUnprocessable();
    expect(ReportExport::count())->toBe(0);
});

it('computes dashboard cards from transactions using each event date', function () {
    test()->getJson('/api/v1/dashboard/summary?period=custom&date_from=2026-11-01&date_to=2026-11-30')->assertOk()->assertJson(['data' => [
        'total_requisitions' => 1, 'requested_quantity' => '20.000', 'purchased_quantity' => '25.000', 'delivered_quantity' => '10.000',
        'total_purchased_amount' => '10050.00', 'total_paid_amount' => '1000.00', 'total_outstanding_amount' => '9050.00',
    ]]);
    test()->getJson('/api/v1/dashboard/charts')->assertOk()->assertJsonStructure(['data' => ['daily_requisitions', 'monthly_requisitions', 'item_quantities', 'fulfilment', 'monthly_purchase_amount', 'vendor_outstanding', 'monthly_payments']]);
    test()->getJson('/api/v1/dashboard/recent-activities')->assertOk();
});

it('hides financial dashboard figures without finance permission', function () {
    actingWithPermissions(['dashboard.view']);
    $data = test()->getJson('/api/v1/dashboard/summary')->assertOk()->json('data');
    expect($data)->not->toHaveKey('total_paid_amount')
        ->and(test()->getJson('/api/v1/dashboard/charts')->json('data'))->not->toHaveKey('vendor_outstanding')
        ->and(test()->getJson('/api/v1/dashboard/recent-activities')->json('data'))->toHaveKeys(['status_updates'])->not->toHaveKey('payments');
});
