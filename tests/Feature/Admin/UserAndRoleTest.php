<?php

use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\Support\Purchasing;
use Tests\Support\Scenario;

it('creates users with roles and audits it, enforcing the password policy', function () {
    actingAsRole('Admin');
    $this->postJson('/api/v1/users', ['name' => 'Rafi', 'email' => 'rafi@example.com', 'password' => 'short', 'roles' => ['Viewer']])
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    $id = $this->postJson('/api/v1/users', ['name' => 'Rafi', 'email' => 'rafi@example.com', 'password' => 'correct horse 42', 'roles' => ['Finance Manager']])
        ->assertCreated()->assertJsonPath('data.roles', ['Finance Manager'])->json('data.id');
    expect(User::find($id)->can('payments.create'))->toBeTrue()
        ->and(ActivityLog::where('action', 'user.created')->sole()->new_values)->not->toHaveKey('password');
});

it('lets only a Super Admin grant Super Admin or edit a Super Admin', function () {
    $root = User::factory()->create();
    $root->assignRole('Super Admin');
    actingAsRole('Admin');

    $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'correct horse 42', 'roles' => ['Super Admin']])->assertForbidden();
    $this->putJson("/api/v1/users/{$root->id}", ['name' => 'Hijacked'])->assertForbidden();

    superAdmin();
    $this->putJson("/api/v1/users/{$root->id}", ['name' => 'Renamed'])->assertOk();
});

it('records role changes and revokes tokens when a user is deactivated', function () {
    $target = User::factory()->create();
    $target->assignRole('Viewer');
    $target->createToken('phone');
    actingAsRole('Admin');

    $this->putJson("/api/v1/users/{$target->id}", ['roles' => ['Purchase Manager']])->assertOk();
    $log = ActivityLog::where('action', 'user.roles_changed')->sole();
    expect($log->old_values['roles'])->toBe(['Viewer'])->and($log->new_values['roles'])->toBe(['Purchase Manager']);

    $this->putJson("/api/v1/users/{$target->id}", ['is_active' => false])->assertOk();
    expect($target->tokens()->count())->toBe(0);
});

it('refuses self-deactivation', function () {
    $me = actingAsRole('Admin');
    $this->putJson("/api/v1/users/{$me->id}", ['is_active' => false])->assertJsonPath('code', 'cannot_deactivate_self');
});

it('lets only Super Admin edit roles, never the Super Admin role itself, and audits permission changes', function () {
    $viewer = Role::findByName('Viewer');
    actingAsRole('Admin');
    $this->putJson("/api/v1/roles/{$viewer->id}", ['permissions' => ['dashboard.view']])->assertForbidden();
    $this->getJson('/api/v1/roles')->assertOk();

    superAdmin();
    $this->putJson("/api/v1/roles/{$viewer->id}", ['permissions' => ['dashboard.view', 'analyzer.view']])->assertOk()
        ->assertJsonPath('data.permissions', ['dashboard.view', 'analyzer.view']);
    expect(ActivityLog::where('action', 'role.permissions_changed')->sole()->new_values['removed'])->toContain('requisitions.view');
    $this->putJson('/api/v1/roles/'.Role::findByName('Super Admin', 'web')->id, ['permissions' => []])->assertJsonPath('code', 'role_locked');
    $this->putJson("/api/v1/roles/{$viewer->id}", ['permissions' => ['made.up']])->assertUnprocessable();
});

it('enforces the seeded role matrix on the API', function (string $role, string $method, string $uri, int $status) {
    actingAsRole($role);
    $this->json($method, $uri, [])->assertStatus($status);
})->with([
    'viewer cannot create POs' => ['Viewer', 'POST', '/api/v1/purchase-orders', 403],
    'viewer cannot see payments' => ['Viewer', 'GET', '/api/v1/payments', 403],
    'viewer sees the analyzer' => ['Viewer', 'GET', '/api/v1/analyzer/summary', 200],
    'officer cannot approve requisitions' => ['Requisition Officer', 'POST', '/api/v1/requisitions/1/approve', 403],
    'receiving officer cannot reverse deliveries' => ['Delivery / Receiving Officer', 'POST', '/api/v1/deliveries/1/reverse', 403],
    'receiving officer cannot see money reports' => ['Delivery / Receiving Officer', 'GET', '/api/v1/reports/outstanding', 403],
    'purchase manager cannot record payments' => ['Purchase Manager', 'POST', '/api/v1/payments', 403],
    'finance manager cannot create POs' => ['Finance Manager', 'POST', '/api/v1/purchase-orders', 403],
    'finance manager validates payments' => ['Finance Manager', 'POST', '/api/v1/payments', 422],
    'inventory manager cannot manage users' => ['Inventory Manager', 'GET', '/api/v1/users', 403],
]);

it('exposes activity logs read-only with filters', function () {
    actingAsRole('Admin');
    $this->postJson('/api/v1/vendors', ['name' => 'Audited Vendor'])->assertCreated();
    $this->getJson('/api/v1/activity-logs?module=vendors')->assertOk()->assertJsonPath('data.0.action', 'vendor.created');

    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'activity-logs') && array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']));
    expect($writes)->toBeEmpty();
    actingAsRole('Purchase Manager');
    $this->getJson('/api/v1/activity-logs')->assertForbidden();
});

it('detects corrupted rows with the integrity check', function () {
    $item = Item::factory()->create();
    Scenario::requisition([[$item, 10]]);
    $po = Purchasing::purchased(Vendor::factory()->create(), [[$item, 10]]);
    $this->artisan('integrity:check')->assertSuccessful();

    DB::table('purchase_order_items')->where('id', $po->items()->first()->id)->update(['ordered_quantity' => 11]);
    $this->artisan('integrity:check')->assertFailed();
});
