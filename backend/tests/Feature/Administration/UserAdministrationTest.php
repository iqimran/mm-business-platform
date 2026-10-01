<?php

namespace Tests\Feature\Administration;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

class UserAdministrationTest extends AdministrationTestCase
{
    private const PASSWORD = 'Initial-Pass-42';

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->postJson('/api/v1/users', [])->assertUnauthorized();
    }

    public function test_user_management_requires_permissions(): void
    {
        $actor = $this->userWith([], [$this->branchA]);
        $target = $this->memberOf([$this->branchA]);

        $this->actingAs($actor)->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($actor)->getJson("/api/v1/users/{$target->id}")->assertForbidden();
        $this->actingAs($actor)->postJson('/api/v1/users', ['name' => 'X'])->assertForbidden();
        $this->actingAs($actor)->putJson("/api/v1/users/{$target->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($actor)->deleteJson("/api/v1/users/{$target->id}")->assertForbidden();
    }

    public function test_global_admin_lists_all_users_with_pagination(): void
    {
        $admin = $this->superAdmin();
        $this->memberOf([$this->branchB]);

        $this->actingAs($admin)->getJson('/api/v1/users?per_page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonMissingPath('data.items.0.password');
    }

    public function test_branch_admin_only_sees_users_of_their_branches(): void
    {
        $admin = $this->branchAdmin();
        $colleague = $this->memberOf([$this->branchA]);
        $outsider = $this->memberOf([$this->branchB]);

        $ids = collect($this->actingAs($admin)->getJson('/api/v1/users')->assertOk()->json('data.items'))->pluck('id');

        $this->assertEqualsCanonicalizing([$admin->id, $colleague->id], $ids->all());
        $this->actingAs($admin)->getJson("/api/v1/users/{$outsider->id}")->assertForbidden();
        $this->actingAs($admin)->getJson("/api/v1/users/{$colleague->id}")->assertOk();
    }

    public function test_admin_creates_user_with_roles_and_branches(): void
    {
        $admin = $this->superAdmin();
        $role = $this->roleWith(['user.view'], 'Viewer');

        $response = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'New Person',
            'email' => ' New.Person@Example.COM ',
            'password' => self::PASSWORD,
            'role_ids' => [$role->id],
            'branch_ids' => [$this->branchA->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'new.person@example.com')
            ->assertJsonPath('data.roles.0.name', 'Viewer')
            ->assertJsonPath('data.branches.0.code', 'A')
            ->assertJsonMissingPath('data.password');

        $user = User::where('email', 'new.person@example.com')->first();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

        $log = AuditLog::where('action', 'user.created')->where('entity_id', $user->id)->first();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame([$role->id], $log->new_values['role_ids']);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($log->new_values));
    }

    public function test_user_creation_is_validated(): void
    {
        $admin = $this->superAdmin();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => '',
            'email' => 'TAKEN@example.com',
            'password' => 'weak',
            'role_ids' => ['nonexistent'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'role_ids.0']);
    }

    public function test_branch_admin_must_assign_users_to_their_own_branches(): void
    {
        $admin = $this->branchAdmin();
        $payload = ['name' => 'N', 'email' => 'n@example.com', 'password' => self::PASSWORD];

        $this->actingAs($admin)->postJson('/api/v1/users', $payload)
            ->assertJsonValidationErrors('branch_ids');
        $this->actingAs($admin)->postJson('/api/v1/users', $payload + ['branch_ids' => [$this->branchB->id]])
            ->assertJsonValidationErrors('branch_ids.0');
        $this->actingAs($admin)->postJson('/api/v1/users', $payload + ['branch_ids' => [$this->branchA->id]])
            ->assertCreated();
    }

    public function test_admin_cannot_grant_roles_with_permissions_they_lack(): void
    {
        $admin = $this->branchAdmin();
        $superRole = Role::where('name', RoleSeeder::SUPER_ADMIN)->first();

        $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Escalated', 'email' => 'esc@example.com', 'password' => self::PASSWORD,
            'role_ids' => [$superRole->id], 'branch_ids' => [$this->branchA->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'esc@example.com']);
    }

    public function test_admin_updates_user_and_changes_are_audited_without_password(): void
    {
        $admin = $this->superAdmin();
        $user = $this->memberOf([$this->branchA]);
        $oldName = $user->name;

        $this->actingAs($admin)->putJson("/api/v1/users/{$user->id}", [
            'name' => 'Renamed',
            'is_active' => false,
            'password' => 'Reset-Pass-2026',
        ])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.is_active', false);

        $this->assertTrue(Hash::check('Reset-Pass-2026', $user->fresh()->password));
        $log = AuditLog::where('action', 'user.updated')->first();
        $this->assertSame(['name' => $oldName, 'is_active' => true], $log->old_values);
        $this->assertSame(['name' => 'Renamed', 'is_active' => false, 'password_changed' => true], $log->new_values);
        $this->assertStringNotContainsString('Reset-Pass-2026', json_encode([$log->old_values, $log->new_values]));
    }

    public function test_admin_cannot_deactivate_or_delete_themselves(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson("/api/v1/users/{$admin->id}", ['is_active' => false])
            ->assertJsonValidationErrors('is_active');
        $this->actingAs($admin)->deleteJson("/api/v1/users/{$admin->id}")->assertForbidden();
        $this->actingAs($admin)->putJson("/api/v1/users/{$admin->id}/roles", ['role_ids' => []])->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_cannot_manage_more_privileged_users(): void
    {
        $admin = $this->branchAdmin();
        $superAdmin = $this->superAdmin();
        $superAdmin->branches()->attach($this->branchA);

        // Visible (shares branch A), but not manageable: resetting their password would be escalation.
        $this->actingAs($admin)->getJson("/api/v1/users/{$superAdmin->id}")->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/users/{$superAdmin->id}", ['password' => 'Takeover-Pass-1'])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/v1/users/{$superAdmin->id}")->assertForbidden();
    }

    public function test_deactivated_privileged_user_still_outranks_admin(): void
    {
        $admin = $this->branchAdmin();
        $superAdmin = $this->superAdmin();
        $superAdmin->branches()->attach($this->branchA);
        $superAdmin->update(['is_active' => false]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$superAdmin->id}", ['is_active' => true])->assertForbidden();
        $this->assertFalse($superAdmin->fresh()->is_active);
    }

    public function test_admin_cannot_manage_users_belonging_to_other_branches(): void
    {
        $admin = $this->branchAdmin();
        $shared = $this->memberOf([$this->branchA, $this->branchB]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$shared->id}", ['name' => 'X'])->assertForbidden();
    }

    public function test_admin_syncs_user_roles_with_audit(): void
    {
        $admin = $this->superAdmin();
        $user = $this->memberOf([$this->branchA]);
        $role = $this->roleWith(['user.view']);

        $this->actingAs($admin)->putJson("/api/v1/users/{$user->id}/roles", ['role_ids' => [$role->id]])
            ->assertOk()->assertJsonPath('data.roles.0.id', $role->id);

        $this->assertTrue($user->fresh()->hasPermission('user.view'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.roles_updated', 'entity_id' => $user->id]);
    }

    public function test_role_assignment_cannot_escalate(): void
    {
        $admin = $this->branchAdmin();
        $user = $this->memberOf([$this->branchA]);
        $globalRole = $this->roleWith(['branch.access_all']);

        $this->actingAs($admin)->putJson("/api/v1/users/{$user->id}/roles", ['role_ids' => [$globalRole->id]])
            ->assertForbidden();
        $this->assertFalse($user->fresh()->canAccessAllBranches());
    }

    public function test_branch_assignment_is_limited_to_admins_branches(): void
    {
        $admin = $this->branchAdmin();
        $user = $this->memberOf([$this->branchA]);

        $this->actingAs($admin)->putJson("/api/v1/users/{$user->id}/branches", ['branch_ids' => [$this->branchB->id]])
            ->assertJsonValidationErrors('branch_ids.0');

        $global = $this->superAdmin();
        $this->actingAs($global)->putJson("/api/v1/users/{$user->id}/branches", ['branch_ids' => [$this->branchA->id, $this->branchB->id]])
            ->assertOk()->assertJsonCount(2, 'data.branches');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.branches_updated', 'entity_id' => $user->id]);
    }

    public function test_user_without_history_can_be_deleted_others_must_be_deactivated(): void
    {
        $admin = $this->superAdmin();
        $fresh = $this->memberOf([$this->branchA]);
        $active = $this->memberOf([$this->branchA]);
        AuditLog::create(['user_id' => $active->id, 'action' => 'auth.login', 'entity_type' => 'user', 'entity_id' => $active->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$fresh->id}")->assertOk();
        $this->assertModelMissing($fresh);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.deleted', 'entity_id' => $fresh->id]);

        $this->actingAs($admin)->deleteJson("/api/v1/users/{$active->id}")
            ->assertConflict()
            ->assertJson(['success' => false]);
        $this->assertModelExists($active);
    }

    public function test_unknown_user_returns_not_found(): void
    {
        $this->actingAs($this->superAdmin())->getJson('/api/v1/users/01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Resource not found.']);
    }
}
