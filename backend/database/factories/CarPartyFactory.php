<?php

namespace Database\Factories;

use App\Modules\Car\Models\CarParty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarParty>
 */
class CarPartyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+8801'.fake()->unique()->numerify('#########'),
            'email' => fake()->safeEmail(),
            'is_active' => true,
        ];
    }
}
