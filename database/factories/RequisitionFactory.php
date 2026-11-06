<?php

namespace Database\Factories;

use App\Enums\RequisitionStatus;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Requisition> */
class RequisitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Requisition '.fake()->words(2, true),
            'requisition_date' => now(config('app.business_timezone'))->toDateString(),
            'status' => RequisitionStatus::Draft,
            'created_by' => User::factory(),
        ];
    }
}
