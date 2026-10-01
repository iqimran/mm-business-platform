<?php

namespace Tests\Feature\Administration;

use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AdministrationTestCase extends TestCase
{
    use RefreshDatabase;

    /** A typical branch-scoped administrator (no global access, no role management). */
    protected const BRANCH_ADMIN_PERMISSIONS = [
        'user.view', 'user.create', 'user.update', 'user.delete',
        'role.view', 'branch.view', 'branch.create', 'branch.update',
        'setting.view', 'audit.view',
    ];

    protected Branch $branchA;

    protected Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->branchA = Branch::factory()->create(['code' => 'A', 'name' => 'Branch A']);
        $this->branchB = Branch::factory()->create(['code' => 'B', 'name' => 'Branch B']);
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleSeeder::SUPER_ADMIN)->first());

        return $user;
    }

    protected function branchAdmin(array $extraPermissions = []): User
    {
        return $this->userWith([...self::BRANCH_ADMIN_PERMISSIONS, ...$extraPermissions], [$this->branchA]);
    }

    /**
     * @param  array<string>  $permissions
     * @param  array<Branch>  $branches
     */
    protected function userWith(array $permissions, array $branches = []): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($this->roleWith($permissions));
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }

    /**
     * @param  array<string>  $permissions
     */
    protected function roleWith(array $permissions, ?string $name = null): Role
    {
        $role = Role::factory()->create($name ? ['name' => $name] : []);
        $role->permissions()->attach(Permission::whereIn('name', $permissions)->pluck('id'));

        return $role;
    }

    /**
     * @param  array<Branch>  $branches
     */
    protected function memberOf(array $branches): User
    {
        $user = User::factory()->create();
        $user->branches()->attach(collect($branches)->pluck('id'));

        return $user;
    }
}
