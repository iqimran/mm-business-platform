<?php

namespace Database\Factories;

use App\Modules\Identity\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'test.'.fake()->unique()->lexify('????????'),
            'description' => fake()->sentence(),
        ];
    }
}
