<?php

namespace Database\Factories;

use App\Modules\Car\Models\CarDealer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarDealer>
 */
class CarDealerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Motors',
            'phone' => '+8801'.fake()->unique()->numerify('#########'),
            'email' => fake()->companyEmail(),
            'is_active' => true,
        ];
    }
}
