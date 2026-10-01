<?php

namespace Tests\Feature\Administration;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branch\Models\Branch;

class BranchAdministrationTest extends AdministrationTestCase
{
    public function test_branch_endpoints_require_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/branches')->assertUnauthorized();

        $actor = $this->userWith([], [$this->branchA]);
        $this->actingAs($actor)->getJson('/api/v1/branches')->assertForbidden();
        $this->actingAs($actor)->postJson('/api/v1/branches', ['code' => 'C', 'name' => 'C'])->assertForbidden();
        $this->actingAs($actor)->putJson("/api/v1/branches/{$this->branchA->id}", ['name' => 'X'])->assertForbidden();
    }

    public function test_global_admin_sees_all_branches_branch_admin_only_theirs(): void
    {
        $this->branchB->update(['is_active' => false]);

        $this->actingAs($this->superAdmin())->getJson('/api/v1/branches')
            ->assertOk()->assertJsonPath('data.pagination.total', 2);

        $admin = $this->branchAdmin();
        $this->actingAs($admin)->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.code', 'A');
        $this->actingAs($admin)->getJson("/api/v1/branches/{$this->branchB->id}")->assertForbidden();
    }

    public function test_admin_creates_branch_with_normalized_code_and_audit(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin)->postJson('/api/v1/branches', [
            'code' => ' dhk-01 ',
            'name' => 'Dhaka Showroom',
            'email' => 'dhaka@example.com',
        ]);

        $response->assertCreated()->assertJsonPath('data.code', 'DHK-01')->assertJsonPath('data.is_active', true);
        $branch = Branch::where('code', 'DHK-01')->first();
        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.created', 'branch_id' => $branch->id, 'user_id' => $admin->id]);
    }

    public function test_branch_input_is_validated(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson('/api/v1/branches', ['code' => 'a', 'name' => 'Duplicate'])
            ->assertJsonValidationErrors('code');
        $this->actingAs($admin)->postJson('/api/v1/branches', ['code' => 'bad code!', 'name' => '', 'email' => 'nope'])
            ->assertJsonValidationErrors(['code', 'name', 'email']);
    }

    public function test_branch_admin_is_assigned_to_branch_they_create(): void
    {
        $admin = $this->branchAdmin();

        $id = $this->actingAs($admin)->postJson('/api/v1/branches', ['code' => 'NEW', 'name' => 'New Branch'])
            ->assertCreated()->json('data.id');

        $this->assertTrue($admin->fresh()->canAccessBranch($id));
        $this->actingAs($admin->fresh())->getJson("/api/v1/branches/{$id}")->assertOk();
    }

    public function test_branch_admin_updates_own_branch_but_not_others(): void
    {
        $admin = $this->branchAdmin();

        $this->actingAs($admin)->putJson("/api/v1/branches/{$this->branchA->id}", ['name' => 'Renamed A', 'phone' => '0123'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed A');
        $log = AuditLog::where('action', 'branch.updated')->first();
        $this->assertSame($this->branchA->id, $log->branch_id);
        $this->assertSame('Branch A', $log->old_values['name']);

        $this->actingAs($admin)->putJson("/api/v1/branches/{$this->branchB->id}", ['name' => 'Hijack'])->assertForbidden();
        $this->assertSame('Branch B', $this->branchB->fresh()->name);
    }

    public function test_branches_cannot_be_deleted(): void
    {
        $this->actingAs($this->superAdmin())->deleteJson("/api/v1/branches/{$this->branchA->id}")->assertMethodNotAllowed();
        $this->assertModelExists($this->branchA);
    }
}
