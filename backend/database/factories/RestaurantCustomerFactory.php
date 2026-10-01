<?php

namespace Database\Factories;

use App\Modules\Restaurant\Models\RestaurantCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantCustomer>
 */
class RestaurantCustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+8801'.fake()->unique()->numerify('#########'),
            'is_active' => true,
        ];
    }
}
