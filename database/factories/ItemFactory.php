<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Item> */
class ItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'ITM-'.fake()->unique()->numerify('#####'),
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'default_unit_id' => fn () => Unit::where('code', 'pcs')->value('id'),
        ];
    }

    public function unit(string $code): static
    {
        return $this->state(['default_unit_id' => Unit::where('code', $code)->value('id')]);
    }
}
