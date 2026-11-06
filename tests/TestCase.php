<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Seed RBAC + units for every RefreshDatabase test (production-shaped baseline, no demo data). */
    protected bool $seed = true;

    protected string $seeder = BaseSeeder::class;
}
