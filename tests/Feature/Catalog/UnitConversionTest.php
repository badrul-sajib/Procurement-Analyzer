<?php

use App\Models\Item;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Support\UnitConverter;

function unitId(string $code): int
{
    return Unit::where('code', $code)->value('id');
}

it('resolves item-specific conversions before global ones', function () {
    $pen = Item::factory()->unit('pcs')->create();
    $other = Item::factory()->unit('pcs')->create();
    UnitConversion::create(['item_id' => null, 'from_unit_id' => unitId('box'), 'to_unit_id' => unitId('pcs'), 'factor' => 10]);
    UnitConversion::create(['item_id' => $pen->id, 'from_unit_id' => unitId('box'), 'to_unit_id' => unitId('pcs'), 'factor' => 12]);

    expect(UnitConverter::factor($pen->id, unitId('pcs'), unitId('box')))->toBe('12.000000')
        ->and(UnitConverter::factor($other->id, unitId('pcs'), unitId('box')))->toBe('10.000000')
        ->and(UnitConverter::factor($pen->id, unitId('pcs'), unitId('pcs')))->toBe('1.000000')
        ->and(UnitConverter::factor($pen->id, unitId('pcs'), unitId('ream')))->toBeNull(); // no rule: never guessed
});

it('creates conversions, rejecting duplicates per scope and invalid factors', function () {
    actingWithPermissions(['master_data.manage']);
    $item = Item::factory()->create();
    $payload = ['from_unit_id' => unitId('box'), 'to_unit_id' => unitId('pcs'), 'factor' => '24'];

    $this->postJson('/api/v1/unit-conversions', $payload)->assertCreated()->assertJsonPath('data.factor', '24.000000');
    $this->postJson('/api/v1/unit-conversions', $payload)->assertUnprocessable()->assertJsonValidationErrors('from_unit_id');
    $this->postJson('/api/v1/unit-conversions', $payload + ['item_id' => $item->id])->assertCreated(); // item scope is separate
    $this->postJson('/api/v1/unit-conversions', ['factor' => '0'] + $payload + ['item_id' => $item->id])->assertUnprocessable();
    $this->postJson('/api/v1/unit-conversions', ['to_unit_id' => unitId('box')] + $payload)->assertUnprocessable()->assertJsonValidationErrors('from_unit_id');
});

it('requires master data permission to change conversions', function () {
    actingWithPermissions(['items.view']);
    $this->getJson('/api/v1/unit-conversions')->assertOk();
    $this->postJson('/api/v1/unit-conversions', ['from_unit_id' => unitId('box'), 'to_unit_id' => unitId('pcs'), 'factor' => 2])->assertForbidden();
});
