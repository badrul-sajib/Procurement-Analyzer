<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
// Concurrency tests need committed data visible to a second process, so no wrapping transaction.
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Concurrency');

/** Authenticated user holding exactly these permissions (via a throwaway role). */
function userWithPermissions(array $permissions = []): User
{
    $user = User::factory()->create();
    if ($permissions) {
        $user->givePermissionTo($permissions);
    }

    return $user;
}

function actingWithPermissions(array $permissions = []): User
{
    $user = userWithPermissions($permissions);
    test()->actingAs($user, 'sanctum');

    return $user;
}

function actingAsRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    test()->actingAs($user, 'sanctum');

    return $user;
}

function superAdmin(): User
{
    return actingAsRole(PermissionCatalog::SUPER_ADMIN);
}
