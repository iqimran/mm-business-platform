<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\Restaurant\Models\Hall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Hall>
 */
class HallFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'Hall '.fake()->unique()->bothify('??-###'),
            'capacity' => fake()->numberBetween(50, 500),
            'is_active' => true,
        ];
    }
}
