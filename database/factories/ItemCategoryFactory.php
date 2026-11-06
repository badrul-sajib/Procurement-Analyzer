<?php

namespace Database\Factories;

use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ItemCategory> */
class ItemCategoryFactory extends Factory
{
    public function definition(): array
    {
        return ['code' => fake()->unique()->bothify('C-###'), 'name' => fake()->unique()->words(2, true)];
    }
}
