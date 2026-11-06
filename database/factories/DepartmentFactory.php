<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('D-###'), 'name' => fake()->unique()->words(2, true)];
    }
}
