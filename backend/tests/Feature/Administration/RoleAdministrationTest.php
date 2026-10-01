<?php

namespace Tests\Feature\Administration;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Database\Seeders\RoleSeeder;

class RoleAdministrationTest extends AdministrationTestCase
{
    public function test_role_endpoints_require_permissions(): void
    {
        $this->getJson('/api/v1/roles')->assertUnauthorized();

        $actor = $this->userWith(['user.view']);
        $role = $this->roleWith([]);

        $this->actingAs($actor)->getJson('/api/v1/roles')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/v1/permissions')->assertForbidden();
        $this->actingAs($actor)->postJson('/api/v1/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();
        $this->actingAs($actor)->putJson("/api/v1/roles/{$role->id}", ['name' => 'Y'])->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/v1/roles/{$role->id}")->assertForbidden();
    }

    public function test_roles_and_permissions_can_be_listed(): void
    {
        $viewer = $this->userWith(['role.view']);

        $this->actingAs($viewer)->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', Role::count())
            ->assertJsonStructure(['data' => ['items' => [['id', 'name', 'is_system', 'permissions', 'users_count']]]]);

        $this->actingAs($viewer)->getJson('/api/v1/permissions')
            ->assertOk()
            ->assertJsonCount(Permission::count(), 'data')
            ->assertJsonFragment(['name' => 'user.view', 'module' => 'user']);
    }

    public function test_admin_creates_role_with_permissions(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->postJson('/api/v1/roles', [
            'name' => 'Cashier',
            'description' => 'Front desk',
            'permissions' => ['user.view', 'branch.view'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Cashier')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permissions', ['branch.view', 'user.view']);

        $log = AuditLog::where('action', 'role.created')->first();
        $this->assertSame(['branch.view', 'user.view'], $log->new_values['permissions']);
    }

    public function test_role_input_is_validated(): void
    {
        $admin = $this->superAdmin();
        $this->roleWith([], 'Cashier');

        $this->actingAs($admin)->postJson('/api/v1/roles', ['name' => 'CASHIER', 'permissions' => ['does.not_exist']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'permissions.0']);
        $this->actingAs($admin)->postJson('/api/v1/roles', ['name' => '   '])
            ->assertJsonValidationErrors(['name']);
    }

    public function test_role_permissions_cannot_exceed_actors_permissions(): void
    {
        $manager = $this->userWith(['role.view', 'role.create', 'role.update', 'user.view']);

        $this->actingAs($manager)->postJson('/api/v1/roles', ['name' => 'Escalator', 'permissions' => ['user.view', 'user.delete']])
            ->assertForbidden();
        $this->assertDatabaseMissing('roles', ['name' => 'Escalator']);

        $this->actingAs($manager)->postJson('/api/v1/roles', ['name' => 'Reader', 'permissions' => ['user.view']])
            ->assertCreated();
    }

    public function test_actor_cannot_modify_roles_more_powerful_than_themselves(): void
    {
        $manager = $this->userWith(['role.view', 'role.update', 'role.delete']);
        $superRole = Role::where('name', RoleSeeder::SUPER_ADMIN)->first();

        $this->actingAs($manager)->putJson("/api/v1/roles/{$superRole->id}", ['permissions' => []])->assertForbidden();
        $this->assertSame(Permission::count(), $superRole->permissions()->count());
    }

    public function test_admin_updates_role_and_audit_captures_permission_diff(): void
    {
        $admin = $this->superAdmin();
        $role = $this->roleWith(['user.view'], 'Clerk');

        $this->actingAs($admin)->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Senior Clerk',
            'permissions' => ['user.view', 'user.update'],
        ])->assertOk()->assertJsonPath('data.permissions', ['user.update', 'user.view']);

        $log = AuditLog::where('action', 'role.updated')->first();
        $this->assertSame(['name' => 'Clerk', 'permissions' => ['user.view']], $log->old_values);
        $this->assertSame(['name' => 'Senior Clerk', 'permissions' => ['user.update', 'user.view']], $log->new_values);
    }

    public function test_system_roles_cannot_be_renamed_or_deleted(): void
    {
        $admin = $this->superAdmin();
        $system = Role::where('name', 'General User')->first();

        $this->actingAs($admin)->putJson("/api/v1/roles/{$system->id}", ['name' => 'Renamed'])
            ->assertJsonValidationErrors('name');
        $this->actingAs($admin)->deleteJson("/api/v1/roles/{$system->id}")->assertConflict();
        $this->assertModelExists($system);
    }

    public function test_roles_in_use_cannot_be_deleted_unused_can(): void
    {
        $admin = $this->superAdmin();
        $used = $this->roleWith([]);
        $this->memberOf([])->roles()->attach($used);
        $unused = $this->roleWith(['user.view']);

        $this->actingAs($admin)->deleteJson("/api/v1/roles/{$used->id}")->assertConflict();
        $this->actingAs($admin)->deleteJson("/api/v1/roles/{$unused->id}")->assertOk();

        $this->assertModelMissing($unused);
        $this->assertDatabaseHas('audit_logs', ['action' => 'role.deleted', 'entity_id' => $unused->id]);
    }
}
