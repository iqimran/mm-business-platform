<?php

namespace Database\Factories;

use App\Modules\Branch\Models\Branch;
use App\Modules\Car\Models\Car;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Car>
 */
class CarFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'brand' => fake()->randomElement(['Toyota', 'Honda', 'Nissan', 'Mitsubishi']),
            'model' => fake()->randomElement(['Corolla', 'Civic', 'X-Trail', 'Pajero']),
            'model_year' => fake()->numberBetween(2010, 2025),
            'color' => fake()->safeColorName(),
            'chassis_number' => strtoupper(fake()->unique()->bothify('??##-######')),
            'engine_number' => strtoupper(fake()->unique()->bothify('ENG######')),
            'mileage_km' => fake()->numberBetween(1000, 150000),
        ];
    }
}
