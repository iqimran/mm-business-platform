<?php

namespace Database\Factories;

use App\Modules\Restaurant\Models\RestaurantSupplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantSupplier>
 */
class RestaurantSupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'contact_person' => fake()->name(),
            'phone' => '+8801'.fake()->unique()->numerify('#########'),
            'is_active' => true,
        ];
    }
}
