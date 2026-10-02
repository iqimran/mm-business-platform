<?php

namespace Database\Factories;

use App\Modules\Restaurant\Models\EventMenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventMenuItem>
 */
class EventMenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }
}
