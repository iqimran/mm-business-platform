<?php

namespace Tests\Feature\Authorization;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;

class PermissionAuthorizationTest extends AuthorizationTestCase
{
    public function test_unauthenticated_access_is_blocked(): void
    {
        $this->getJson('/api/v1/__test/reports')
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_permission_granted_through_role(): void
    {
        $user = $this->userWithPermissions(['test.report.view']);

        $this->actingAs($user)->getJson('/api/v1/__test/reports')->assertOk();
    }

    public function test_permission_denied_without_grant(): void
    {
        $user = $this->userWithPermissions(['test.other.view']);

        $this->actingAs($user)->getJson('/api/v1/__test/reports')
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'This action is unauthorized.']);
    }

    public function test_user_without_roles_is_denied(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/__test/reports')->assertForbidden();
    }

    public function test_permissions_from_multiple_roles_are_combined(): void
    {
        $user = $this->userWithPermissions(['test.other.view']);
        $second = Role::factory()->create();
        $second->permissions()->attach(Permission::factory()->create(['name' => 'test.report.view']));
        $user->roles()->attach($second);

        $this->actingAs($user)->getJson('/api/v1/__test/reports')->assertOk();
    }

    public function test_role_names_grant_nothing_by_themselves(): void
    {
        $this->seed(DatabaseSeeder::class);
        // A role literally named "Super Admin" but holding no permissions.
        $user = $this->userWithPermissions([], roleName: 'Super Admin');

        $this->actingAs($user)->getJson('/api/v1/__test/reports')->assertForbidden();
    }

    public function test_custom_role_is_as_effective_as_default_roles(): void
    {
        $user = $this->userWithPermissions(['test.report.view'], roleName: 'Night Auditor');

        $this->actingAs($user)->getJson('/api/v1/__test/reports')->assertOk();
    }

    public function test_revoked_permission_takes_effect_on_next_request(): void
    {
        $user = $this->userWithPermissions(['test.report.view']);
        $this->actingAs($user)->getJson('/api/v1/__test/reports')->assertOk();

        $user->roles()->first()->permissions()->detach();

        $this->actingAs($user->fresh())->getJson('/api/v1/__test/reports')->assertForbidden();
    }

    public function test_inactive_user_holds_no_permissions(): void
    {
        $user = $this->userWithPermissions(['test.report.view']);
        $user->update(['is_active' => false]);

        $this->assertFalse($user->fresh()->hasPermission('test.report.view'));
        $this->assertTrue($user->fresh()->accessibleBranchIds()->isEmpty());
    }

    public function test_gate_abilities_map_to_permissions(): void
    {
        $user = $this->userWithPermissions(['test.report.view']);

        $this->assertTrue($user->can('test.report.view'));
        $this->assertFalse($user->can('test.report.delete'));
        $this->assertFalse($user->can('not-a-permission'));
    }

    public function test_current_user_endpoint_lists_permissions(): void
    {
        $user = $this->userWithPermissions(['test.report.view', 'test.other.view']);

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.permissions', ['test.other.view', 'test.report.view']);
    }
}
