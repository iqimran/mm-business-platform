<?php

namespace Database\Factories;

use App\Modules\Car\Models\CarExpenseType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarExpenseType>
 */
class CarExpenseTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'is_active' => true,
        ];
    }
}
