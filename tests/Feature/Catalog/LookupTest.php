<?php

use App\Models\Department;

it('manages departments, units and categories through one lookup API', function (string $path) {
    actingWithPermissions(['master_data.manage', 'items.view']);

    $id = $this->postJson("/api/v1/{$path}", ['code' => 'NEW-1', 'name' => 'New thing'])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/{$path}/{$id}", ['name' => 'Renamed', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->postJson("/api/v1/{$path}", ['code' => 'NEW-1', 'name' => 'Dup'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->getJson("/api/v1/{$path}?search=renamed")->assertOk()->assertJsonPath('data.0.name', 'Renamed');
})->with(['departments', 'units', 'item-categories']);

it('returns all rows for dropdowns and blocks writes without permission', function () {
    Department::factory()->count(30)->create();
    actingWithPermissions(['requisitions.view']);

    $this->getJson('/api/v1/departments?all=1')->assertOk()->assertJsonCount(30, 'data');
    $this->postJson('/api/v1/departments', ['code' => 'X', 'name' => 'X'])->assertForbidden();
});
