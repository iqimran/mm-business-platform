<?php

namespace Database\Factories;

use App\Modules\Restaurant\Models\MenuCategory;
use App\Modules\Restaurant\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => MenuCategory::factory(),
            'name' => fake()->unique()->words(3, true),
            'price_minor' => fake()->numberBetween(50, 2000) * 100,
            'is_active' => true,
        ];
    }
}
