<?php

use App\Models\ActivityLog;
use App\Models\Vendor;

it('creates a vendor with an auto code and audits updates with only changed fields', function () {
    actingWithPermissions(['vendors.manage', 'vendors.view']);

    $id = $this->postJson('/api/v1/vendors', ['name' => 'Rahman Stationery', 'phone' => '+880 1711-000000', 'email' => 'sales@rahman.test'])
        ->assertCreated()->json('data.id');
    expect(Vendor::find($id)->code)->toBe(sprintf('VEN-%04d', $id));

    $this->putJson("/api/v1/vendors/{$id}", ['name' => 'Rahman Stationery', 'contact_person' => 'Abdur Rahman'])->assertOk();
    $log = ActivityLog::where('action', 'vendor.updated')->sole();
    expect($log->new_values)->toBe(['contact_person' => 'Abdur Rahman']);
});

it('validates vendor input', function () {
    actingWithPermissions(['vendors.manage']);
    $this->postJson('/api/v1/vendors', ['name' => '', 'email' => 'nope', 'phone' => 'call me'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'phone']);
});

it('lists vendors for purchase and finance users but only managers edit', function () {
    $vendor = Vendor::factory()->create();
    actingWithPermissions(['payments.view']);
    $this->getJson('/api/v1/vendors?all=1')->assertOk()->assertJsonCount(1, 'data');
    $this->putJson("/api/v1/vendors/{$vendor->id}", ['name' => 'X'])->assertForbidden();
});
