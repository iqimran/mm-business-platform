<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class RestaurantTestCase extends TestCase
{
    use RefreshDatabase;

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

    /** Strict comparison of audit value maps, ignoring key order (jsonb sorts keys). */
    protected function assertSameValues(array $expected, ?array $actual): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    /** Runs a statement in a savepoint and asserts the database refuses it. */
    protected function assertRejected(callable $statement, ?string $messageContains = null): void
    {
        try {
            DB::transaction($statement);
            $this->fail('The database accepted a forbidden change.');
        } catch (QueryException $e) {
            if ($messageContains !== null) {
                $this->assertStringContainsString($messageContains, $e->getMessage());
            }
        }
    }
}
