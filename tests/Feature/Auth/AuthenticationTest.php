<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\PermissionCatalog;

// The SPA is served by Laravel, so its requests come from the app's own origin (APP_URL in phpunit.xml).
const SPA = ['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'];

it('accepts SPA login on whatever host and port the app is opened on', function () {
    User::factory()->create(['email' => 'officer@example.com']);

    $this->withHeaders(['Origin' => 'http://127.0.0.1:8001', 'Referer' => 'http://127.0.0.1:8001/'])
        ->postJson('http://127.0.0.1:8001/api/v1/auth/login', ['email' => 'officer@example.com', 'password' => 'password'])
        ->assertOk();
});

it('logs an active user into the SPA session and records the login', function () {
    $user = User::factory()->create(['email' => 'officer@example.com']);
    $user->assignRole('Requisition Officer');

    $this->withHeaders(SPA)->postJson('/api/v1/auth/login', ['email' => 'officer@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'officer@example.com')
        ->assertJsonPath('data.roles', ['Requisition Officer'])
        ->assertJsonFragment(['requisitions.upload']);

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and(ActivityLog::where('action', 'auth.login')->where('record_id', $user->id)->exists())->toBeTrue();
    $this->withHeaders(SPA)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

it('rejects wrong passwords, unknown emails and inactive users with the same message', function (array $attrs, string $password) {
    User::factory()->create(['email' => 'x@example.com'] + $attrs);

    $this->withHeaders(SPA)->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => $password])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
})->with([
    'wrong password' => [[], 'nope'],
    'inactive user' => [['is_active' => false], 'password'],
]);

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/tokens', ['email' => 'a@b.co', 'password' => 'x', 'device_name' => 'd']);
    }
    $this->postJson('/api/v1/auth/tokens', ['email' => 'a@b.co', 'password' => 'x', 'device_name' => 'd'])->assertTooManyRequests();
});

it('issues and revokes mobile bearer tokens', function () {
    User::factory()->create(['email' => 'mobile@example.com']);

    $token = $this->postJson('/api/v1/auth/tokens', ['email' => 'mobile@example.com', 'password' => 'password', 'device_name' => 'Android'])
        ->assertCreated()->json('data.token');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('blocks deactivated users who still hold a session or token', function () {
    $user = User::factory()->create(['is_active' => false]);
    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertForbidden()->assertJsonPath('code', 'account_inactive');
});

it('requires authentication for protected endpoints', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('returns every permission for a super admin', function () {
    superAdmin();
    $permissions = $this->getJson('/api/v1/auth/me')->json('data.permissions');
    expect($permissions)->toEqual(PermissionCatalog::PERMISSIONS);
});
