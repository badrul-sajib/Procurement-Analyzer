<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, UnitSeeder::class]);

        $email = env('SUPER_ADMIN_EMAIL', app()->isProduction() ? null : 'admin@example.com');
        $password = env('SUPER_ADMIN_PASSWORD', app()->isProduction() ? null : 'password');
        if (! $email || ! $password) {
            $this->command?->warn('SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD not set: no Super Admin created.');

            return;
        }

        $admin = User::firstOrCreate(['email' => $email], ['name' => 'Super Admin', 'password' => $password]);
        $admin->assignRole(PermissionCatalog::SUPER_ADMIN);

        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
