<?php

namespace Tests\Feature\Database;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = $this->tableCounts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, $this->tableCounts());
        $this->assertSame(count(PermissionSeeder::PERMISSIONS), Permission::count());
    }

    public function test_super_admin_holds_every_permission_and_general_user_none(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(Permission::count(), Role::where('name', 'Super Admin')->first()->permissions()->count());
        $this->assertSame(0, Role::where('name', 'General User')->first()->permissions()->count());
        $this->assertSame(3, Role::where('is_system', true)->count());
    }

    public function test_role_customizations_survive_reseeding(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = Role::where('name', 'Admin')->first();
        $admin->permissions()->detach(Permission::where('name', 'audit.view')->first());

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse($admin->permissions()->where('name', 'audit.view')->exists());
    }

    public function test_development_admin_is_seeded_with_hashed_password_and_branch_access(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.com')->first();

        $this->assertNotSame('password', $admin->password);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue($admin->roles()->where('name', 'Super Admin')->exists());
        $this->assertSame(2, $admin->branches()->count());
    }

    private function tableCounts(): array
    {
        return collect(['users', 'roles', 'permissions', 'role_permission', 'role_user', 'branches', 'branch_user'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }
}
