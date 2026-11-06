<?php

use App\Models\ActivityLog;
use App\Support\Settings;

it('shows settings with business defaults to any signed-in user', function () {
    actingWithPermissions();
    $this->getJson('/api/v1/settings')->assertOk()
        ->assertJsonPath('data.currency_symbol', '৳')
        ->assertJsonPath('data.week_starts_on', 'saturday')
        ->assertJsonPath('data.business_timezone', 'Asia/Dhaka');
});

it('lets only settings managers change settings, and audits the change', function () {
    actingWithPermissions(['dashboard.view']);
    $this->putJson('/api/v1/settings', ['require_po_approval' => false])->assertForbidden();

    actingWithPermissions(['settings.manage']);
    $this->putJson('/api/v1/settings', ['require_po_approval' => false, 'week_starts_on' => 'sunday'])
        ->assertOk()->assertJsonPath('data.require_po_approval', false);

    expect(Settings::get('week_starts_on'))->toBe('sunday');
    $log = ActivityLog::where('action', 'settings.updated')->sole();
    expect($log->old_values)->toBe(['week_starts_on' => 'saturday', 'require_po_approval' => true]);
});

it('validates setting values', function () {
    actingWithPermissions(['settings.manage']);
    $this->putJson('/api/v1/settings', ['week_starts_on' => 'friday', 'max_upload_mb' => 500])
        ->assertUnprocessable()->assertJsonValidationErrors(['week_starts_on', 'max_upload_mb']);
});
