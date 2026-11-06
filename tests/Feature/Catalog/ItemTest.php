<?php

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\Unit;

beforeEach(fn () => $this->ream = Unit::where('code', 'ream')->value('id'));

it('creates an item with aliases and an auto-generated code', function () {
    actingWithPermissions(['items.manage', 'items.view']);

    $res = $this->postJson('/api/v1/items', [
        'name' => 'A4 Paper', 'default_unit_id' => $this->ream, 'aliases' => ['A4 Size Paper', 'Paper A4'],
    ])->assertCreated();

    $item = Item::sole();
    $res->assertJsonPath('data.code', sprintf('ITM-%05d', $item->id))
        ->assertJsonPath('data.default_unit.code', 'ream')
        ->assertJsonCount(2, 'data.aliases');
    expect($item->normalized_name)->toBe('a4 paper')
        ->and(ActivityLog::where('action', 'item.created')->exists())->toBeTrue();
});

it('rejects a name that already identifies another item by name or alias', function (string $name) {
    actingWithPermissions(['items.manage']);
    $existing = Item::factory()->create(['name' => 'A4 Paper']);
    $existing->aliases()->create(['alias' => 'Offset Paper']);

    $this->postJson('/api/v1/items', ['name' => $name, 'default_unit_id' => $this->ream])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
})->with(['same name, different case/punctuation' => 'a4-paper ', 'existing alias' => 'Offset  paper']);

it('rejects an alias that points at a different item', function () {
    actingWithPermissions(['items.manage']);
    Item::factory()->create(['name' => 'Marker']);
    $pen = Item::factory()->create(['name' => 'Ball Pen']);

    $this->postJson("/api/v1/items/{$pen->id}/aliases", ['alias' => 'MARKER'])
        ->assertUnprocessable()->assertJsonPath('errors.alias.0', '"MARKER" already identifies the item "Marker".');
    $this->postJson("/api/v1/items/{$pen->id}/aliases", ['alias' => 'Pen'])->assertCreated();
});

it('finds items by alias, code and name with server-side pagination', function () {
    actingWithPermissions(['items.view']);
    $paper = Item::factory()->create(['name' => 'A4 Paper']);
    $paper->aliases()->create(['alias' => 'Offset Paper']);
    Item::factory()->count(3)->create();

    $this->getJson('/api/v1/items?search=offset')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $paper->id);
    $this->getJson('/api/v1/items?per_page=2&sort=-name')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4);
    $this->getJson('/api/v1/items?sort=password')->assertUnprocessable()->assertJsonValidationErrors('sort');
});

it('locks the default unit once verified requisition lines use the item', function () {
    actingWithPermissions(['items.manage']);
    $item = Item::factory()->unit('ream')->create();
    $requisition = Requisition::factory()->create();
    $line = $requisition->items()->create(['line_no' => 1, 'item_id' => $item->id, 'original_name' => 'x', 'quantity' => 1]);
    $line->forceFill(['conversion_factor' => 1])->save();

    $this->putJson("/api/v1/items/{$item->id}", ['default_unit_id' => Unit::where('code', 'box')->value('id')])
        ->assertUnprocessable()->assertJsonPath('code', 'default_unit_locked');
    $this->putJson("/api/v1/items/{$item->id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
});

it('enforces item permissions', function () {
    actingWithPermissions(['items.view']);
    $this->postJson('/api/v1/items', ['name' => 'X', 'default_unit_id' => $this->ream])->assertForbidden();
    actingWithPermissions([]);
    $this->getJson('/api/v1/items')->assertForbidden();
});

it('ignores mass-assigned protected fields', function () {
    actingWithPermissions(['items.manage']);
    $this->postJson('/api/v1/items', ['name' => 'Stapler', 'default_unit_id' => $this->ream, 'normalized_name' => 'hijack', 'created_by' => 999])
        ->assertCreated();
    expect(Item::sole())->normalized_name->toBe('stapler')->created_by->not->toBe(999);
});
