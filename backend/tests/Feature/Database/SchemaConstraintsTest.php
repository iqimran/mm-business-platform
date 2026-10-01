<?php

namespace Tests\Feature\Database;

use App\Modules\Administration\Models\Setting;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branch\Models\Branch;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_on_postgresql_test_database(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame('mm_platform_test', DB::connection()->getDatabaseName());
    }

    public function test_models_use_ulid_primary_keys(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Str::isUlid($user->id));
    }

    public function test_user_email_is_normalized_and_unique_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => '  Owner@Example.COM ']);
        $this->assertSame('owner@example.com', $user->fresh()->email);

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['email' => 'OWNER@example.com']);
    }

    public function test_database_rejects_non_lowercase_email(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'id' => (string) Str::ulid(),
            'name' => 'Raw',
            'email' => 'Raw@Example.com',
            'password' => 'x',
        ]);
    }

    public function test_role_names_are_unique_case_insensitively(): void
    {
        Role::factory()->create(['name' => 'Cashier']);

        $this->expectException(UniqueConstraintViolationException::class);
        Role::factory()->create(['name' => 'cashier']);
    }

    public function test_permission_name_must_be_dot_namespaced_lowercase(): void
    {
        Permission::factory()->create(['name' => 'car.expense.create']);

        $this->expectException(QueryException::class);
        Permission::factory()->create(['name' => 'Car Create']);
    }

    public function test_branch_code_is_unique_and_formatted(): void
    {
        Branch::factory()->create(['code' => 'HQ']);

        try {
            Branch::factory()->create(['code' => 'HQ']);
            $this->fail('Duplicate branch code was accepted.');
        } catch (UniqueConstraintViolationException) {
        }

        $this->expectException(QueryException::class);
        Branch::factory()->create(['code' => 'bad code']);
    }

    public function test_role_assigned_to_a_user_cannot_be_deleted(): void
    {
        $role = Role::factory()->create();
        User::factory()->create()->roles()->attach($role);

        $this->expectException(QueryException::class);
        $role->delete();
    }

    public function test_deleting_unassigned_role_removes_its_permission_grants(): void
    {
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::factory()->create());

        $role->delete();

        $this->assertDatabaseCount('role_permission', 0);
    }

    public function test_branch_with_assigned_users_cannot_be_deleted(): void
    {
        $branch = Branch::factory()->create();
        User::factory()->create()->branches()->attach($branch);

        $this->expectException(QueryException::class);
        $branch->delete();
    }

    public function test_deleting_user_removes_role_and_branch_assignments(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::factory()->create());
        $user->branches()->attach(Branch::factory()->create());

        $user->delete();

        $this->assertDatabaseCount('role_user', 0);
        $this->assertDatabaseCount('branch_user', 0);
    }

    public function test_relationships_resolve(): void
    {
        $permission = Permission::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach($permission);
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        $user->roles()->attach($role);
        $user->branches()->attach($branch);

        $this->assertTrue($user->roles->first()->is($role));
        $this->assertTrue($user->roles->first()->permissions->first()->is($permission));
        $this->assertTrue($branch->users->first()->is($user));
    }

    public function test_setting_keys_are_unique_and_dot_namespaced(): void
    {
        Setting::create(['key' => 'app.name', 'value' => 'MM Business']);

        try {
            Setting::create(['key' => 'app.name', 'value' => 'Duplicate']);
            $this->fail('Duplicate setting key was accepted.');
        } catch (UniqueConstraintViolationException) {
        }

        $this->expectException(QueryException::class);
        Setting::create(['key' => 'App Name', 'value' => 'x']);
    }

    public function test_setting_values_round_trip_as_json(): void
    {
        Setting::create(['key' => 'app.name', 'value' => 'MM Business']);
        Setting::create(['key' => 'app.features', 'value' => ['imports' => true, 'limit' => 5]]);

        $this->assertSame('MM Business', Setting::where('key', 'app.name')->first()->value);
        // jsonb does not preserve object key order; compare values only.
        $this->assertEquals(['imports' => true, 'limit' => 5], Setting::where('key', 'app.features')->first()->value);
        $this->assertSame('jsonb', DB::scalar("select data_type from information_schema.columns where table_name = 'settings' and column_name = 'value'"));
    }

    public function test_audit_logs_are_append_only(): void
    {
        $log = AuditLog::create([
            'action' => 'user.created',
            'entity_type' => 'user',
            'entity_id' => (string) Str::ulid(),
            'new_values' => ['name' => 'A'],
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertSame(['name' => 'A'], $log->fresh()->new_values);

        try {
            DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'user.deleted']);
            $this->fail('Audit log update was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $log->id)->delete();
    }

    public function test_user_with_audit_history_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        AuditLog::create(['user_id' => $user->id, 'action' => 'auth.login', 'entity_type' => 'user', 'entity_id' => $user->id]);

        $this->expectException(QueryException::class);
        $user->delete();
    }
}
