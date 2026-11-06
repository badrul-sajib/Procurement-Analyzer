<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vendor> */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'VEN-'.fake()->unique()->numerify('####'),
            'name' => fake()->unique()->company(),
            'contact_person' => fake()->name(),
            'phone' => '+8801'.fake()->numerify('#########'),
        ];
    }
}
