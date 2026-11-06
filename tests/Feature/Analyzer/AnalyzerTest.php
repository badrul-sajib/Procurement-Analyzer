<?php

use App\Actions\Requisitions\CancelRequisition;
use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Models\UnitConversion;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->paper = Item::factory()->unit('ream')->create(['name' => 'A4 Paper']);
    $this->marker = Item::factory()->unit('pcs')->create(['name' => 'Marker']);
    $this->barcode = Item::factory()->unit('pcs')->create(['name' => 'Barcode']);
    actingWithPermissions(['analyzer.view']);
});

function analyzerRows(string $query = ''): array
{
    return collect(test()->getJson('/api/v1/analyzer/items?sort=name'.$query)->assertOk()->json('data'))
        ->mapWithKeys(fn ($r) => [$r['item']['name'] => [$r['requisition_count'], $r['requested']]])->all();
}

it('consolidates identical items across requisitions (spec example)', function () {
    Scenario::requisition([[$this->paper, 10], [$this->marker, 5], [$this->barcode, 100]]);
    Scenario::requisition([[$this->paper, 20], [$this->marker, 10], [$this->barcode, 50]]);

    expect(analyzerRows())->toBe([
        'A4 Paper' => [2, '30.000'],
        'Barcode' => [2, '150.000'],
        'Marker' => [2, '15.000'],
    ]);
});

it('keeps requisitions, unique items, lines and physical quantity as separate metrics', function () {
    Scenario::requisition([[$this->paper, 10], [$this->marker, 5], [$this->barcode, 100]]);
    Scenario::requisition([[$this->paper, 20], [$this->marker, 10], [$this->barcode, 50]]);

    $this->getJson('/api/v1/analyzer/summary')->assertOk()->assertJson(['data' => [
        'total_requisitions' => 2, 'unique_items' => 3, 'requisition_lines' => 6,
        'requested_quantity' => '195.000', 'approved_quantity' => '195.000', 'pending_quantity' => '195.000',
        'purchased_quantity' => '0.000', 'delivered_quantity' => '0.000',
    ]]);
});

it('never counts unverified or cancelled requisitions', function () {
    Scenario::requisition([[$this->paper, 10]]);
    Scenario::requisition([[$this->paper, 99]], 'draft');
    $cancelled = Scenario::requisition([[$this->paper, 77]]);
    app(CancelRequisition::class)->handle($cancelled, 'test', $cancelled->created_by);

    expect(analyzerRows())->toBe(['A4 Paper' => [1, '10.000']]);
});

it('counts verified requisitions as requested but only approved quantity as purchasable', function () {
    Scenario::requisition([[$this->paper, 10]], 'verified');
    Scenario::requisition([[$this->paper, 20]], approve: [0 => 15]);

    $row = $this->getJson('/api/v1/analyzer/items')->json('data.0');
    expect($row)->toMatchArray(['requested' => '30.000', 'approved' => '15.000', 'pending' => '15.000', 'purchase_status' => 'not_purchased']);
    $this->getJson('/api/v1/analyzer/items?status=verified')->assertJsonPath('data.0.requested', '10.000');
});

it('consolidates different units only through a defined conversion', function () {
    $box = Unit::where('code', 'box')->value('id');
    UnitConversion::create(['item_id' => $this->marker->id, 'from_unit_id' => $box, 'to_unit_id' => $this->marker->default_unit_id, 'factor' => 10]);
    Scenario::requisition([[$this->marker, 5, 'box']]);
    Scenario::requisition([[$this->marker, 10]]);

    expect(analyzerRows())->toBe(['Marker' => [2, '60.000']]); // 5 boxes × 10 + 10 pieces, never "15"
});

it('filters by requisition date, department, category and search', function () {
    $acc = Department::factory()->create();
    $cat = ItemCategory::factory()->create();
    $this->marker->update(['category_id' => $cat->id]);
    Scenario::requisition([[$this->paper, 10], [$this->marker, 1]], date: '2026-10-15', attrs: ['department_id' => $acc->id]);
    Scenario::requisition([[$this->paper, 20]], date: '2026-11-03');

    expect(analyzerRows('&period=custom&date_from=2026-11-01&date_to=2026-11-30'))->toBe(['A4 Paper' => [1, '20.000']])
        ->and(analyzerRows("&department_id={$acc->id}"))->toBe(['A4 Paper' => [1, '10.000'], 'Marker' => [1, '1.000']])
        ->and(analyzerRows("&category_id={$cat->id}"))->toBe(['Marker' => [1, '1.000']])
        ->and(analyzerRows('&search=paper'))->toBe(['A4 Paper' => [2, '30.000']]);
});

it('sorts and paginates on the server', function () {
    Scenario::requisition([[$this->paper, 10], [$this->marker, 50], [$this->barcode, 30]]);

    $this->getJson('/api/v1/analyzer/items?sort=-requested&per_page=2')->assertOk()
        ->assertJsonPath('data.0.item.name', 'Marker')->assertJsonPath('data.1.item.name', 'Barcode')
        ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/v1/analyzer/items?sort=secret')->assertUnprocessable();
});

it('drills down to the source requisition lines of an item', function () {
    $a = Scenario::requisition([[$this->paper, 10]], date: '2026-10-01');
    $b = Scenario::requisition([[$this->paper, 2, 'ream']], date: '2026-10-05');

    $rows = $this->getJson("/api/v1/analyzer/items/{$this->paper->id}/requisitions")->assertOk()->json('data');
    expect(array_column(array_column($rows, 'requisition'), 'reference_no'))->toBe([$a->reference_no, $b->reference_no])
        ->and($rows[0])->toMatchArray(['requested' => '10.000', 'approved' => '10.000', 'pending' => '10.000']);
});

it('requires analyzer permission', function () {
    actingWithPermissions(['requisitions.view']);
    $this->getJson('/api/v1/analyzer/items')->assertForbidden();
});
