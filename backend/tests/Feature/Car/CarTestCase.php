<?php

namespace Tests\Feature\Car;

use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class CarTestCase extends TestCase
{
    use RefreshDatabase;

    protected const CAR_PERMISSIONS = ['car.view', 'car.create', 'car.update', 'car.delete'];

    protected Branch $branchA;

    protected Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->branchA = Branch::factory()->create(['code' => 'A']);
        $this->branchB = Branch::factory()->create(['code' => 'B']);
    }

    /**
     * @param  array<string>  $permissions
     * @param  array<Branch>  $branches
     */
    protected function userWith(array $permissions, array $branches = []): User
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::whereIn('name', $permissions)->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    /** Car manager for branch A only. */
    protected function managerA(array $extra = []): User
    {
        return $this->userWith([...self::CAR_PERMISSIONS, ...$extra], [$this->branchA]);
    }
}
