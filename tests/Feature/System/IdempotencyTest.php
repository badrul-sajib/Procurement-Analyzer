<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'idempotent'])->post('api/v1/_test/counter', function () {
        $n = cache()->increment('counter');

        return response()->json(['data' => ['n' => $n]], 201);
    });
});

it('replays the first response for a repeated key and applies the operation once', function () {
    actingWithPermissions();
    $headers = ['Idempotency-Key' => 'abc-123'];

    $this->withHeaders($headers)->postJson('/api/v1/_test/counter', ['a' => 1])->assertCreated()->assertJsonPath('data.n', 1);
    $this->withHeaders($headers)->postJson('/api/v1/_test/counter', ['a' => 1])
        ->assertCreated()->assertJsonPath('data.n', 1)->assertHeader('Idempotent-Replayed', 'true');

    expect(cache('counter'))->toBe(1);
});

it('rejects a reused key with a different body', function () {
    actingWithPermissions();
    $this->withHeaders(['Idempotency-Key' => 'k'])->postJson('/api/v1/_test/counter', ['a' => 1])->assertCreated();
    $this->withHeaders(['Idempotency-Key' => 'k'])->postJson('/api/v1/_test/counter', ['a' => 2])
        ->assertStatus(409)->assertJsonPath('code', 'idempotency_mismatch');
});

it('scopes keys per user', function () {
    actingWithPermissions();
    $this->withHeaders(['Idempotency-Key' => 'k'])->postJson('/api/v1/_test/counter')->assertJsonPath('data.n', 1);
    actingWithPermissions();
    $this->withHeaders(['Idempotency-Key' => 'k'])->postJson('/api/v1/_test/counter')->assertJsonPath('data.n', 2);
});
