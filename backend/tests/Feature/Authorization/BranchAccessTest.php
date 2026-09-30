<?php

namespace Tests\Feature\Authorization;

use App\Modules\Branch\Models\Branch;
use Illuminate\Support\Str;

class BranchAccessTest extends AuthorizationTestCase
{
    private Branch $branchA;

    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = Branch::factory()->create(['code' => 'A']);
        $this->branchB = Branch::factory()->create(['code' => 'B']);
    }

    public function test_unauthenticated_branch_access_is_blocked(): void
    {
        $this->getJson("/api/v1/__test/branches/{$this->branchA->id}/dashboard")->assertUnauthorized();
        $this->getJson('/api/v1/__test/records')->assertUnauthorized();
    }

    public function test_branch_access_granted_for_assigned_branch(): void
    {
        $user = $this->userWithPermissions([], [$this->branchA]);

        $this->actingAs($user)->getJson("/api/v1/__test/branches/{$this->branchA->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.branch', $this->branchA->id);
    }

    public function test_branch_access_denied_for_unassigned_branch(): void
    {
        $user = $this->userWithPermissions([], [$this->branchA]);

        $this->actingAs($user)->getJson("/api/v1/__test/branches/{$this->branchB->id}/dashboard")
            ->assertForbidden()
            ->assertJson(['success' => false]);
    }

    public function test_unknown_branch_is_denied_like_an_unassigned_one(): void
    {
        $user = $this->userWithPermissions([], [$this->branchA]);

        $this->actingAs($user)->getJson('/api/v1/__test/branches/'.Str::ulid().'/dashboard')->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/__test/branches/not-a-ulid/dashboard')->assertForbidden();
    }

    public function test_inactive_branch_is_denied_to_assigned_users(): void
    {
        $user = $this->userWithPermissions([], [$this->branchA]);
        $this->branchA->update(['is_active' => false]);

        $this->actingAs($user)->getJson("/api/v1/__test/branches/{$this->branchA->id}/dashboard")->assertForbidden();
    }

    public function test_global_access_comes_from_permission_not_role_name(): void
    {
        $user = $this->userWithPermissions(['branch.access_all'], roleName: 'Regional Auditor');

        $this->actingAs($user)->getJson("/api/v1/__test/branches/{$this->branchA->id}/dashboard")->assertOk();
        $this->actingAs($user)->getJson("/api/v1/__test/branches/{$this->branchB->id}/dashboard")->assertOk();
    }

    public function test_cross_branch_record_access_is_blocked(): void
    {
        $user = $this->userWithPermissions(['test.record.view'], [$this->branchA]);
        $own = $this->record($this->branchA, 'own');
        $foreign = $this->record($this->branchB, 'foreign');

        $this->actingAs($user)->getJson("/api/v1/__test/records/{$own->id}")->assertOk();
        $this->actingAs($user)->getJson("/api/v1/__test/records/{$foreign->id}")->assertForbidden();
    }

    public function test_branch_access_without_permission_is_still_denied(): void
    {
        $user = $this->userWithPermissions([], [$this->branchA]);
        $own = $this->record($this->branchA, 'own');

        $this->actingAs($user)->getJson("/api/v1/__test/records/{$own->id}")->assertForbidden();
    }

    public function test_permission_does_not_bypass_record_branch_check(): void
    {
        // Holding the permission is not enough for another branch's record.
        $user = $this->userWithPermissions(['test.record.view']);
        $foreign = $this->record($this->branchB, 'foreign');

        $this->actingAs($user)->getJson("/api/v1/__test/records/{$foreign->id}")->assertForbidden();
    }

    public function test_listings_are_scoped_to_accessible_branches(): void
    {
        $this->record($this->branchA, 'a-1');
        $this->record($this->branchB, 'b-1');
        $local = $this->userWithPermissions(['test.record.view'], [$this->branchA]);
        $global = $this->userWithPermissions(['test.record.view', 'branch.access_all']);

        $this->actingAs($local)->getJson('/api/v1/__test/records')->assertOk()->assertExactJson([
            'success' => true, 'message' => 'Operation completed successfully.', 'data' => ['a-1'],
        ]);
        $this->actingAs($global)->getJson('/api/v1/__test/records')->assertOk()->assertJsonPath('data', ['a-1', 'b-1']);
    }

    public function test_client_supplied_branch_id_is_validated_against_access(): void
    {
        $user = $this->userWithPermissions(['test.record.create'], [$this->branchA]);

        $this->actingAs($user)->postJson('/api/v1/__test/records', ['branch_id' => $this->branchB->id, 'title' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => 'The selected branch is invalid.']);
        $this->assertDatabaseMissing('test_branch_records', ['branch_id' => $this->branchB->id]);

        $this->actingAs($user)->postJson('/api/v1/__test/records', ['branch_id' => $this->branchA->id, 'title' => 'x'])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $this->branchA->id);
    }

    public function test_current_user_endpoint_lists_accessible_branches(): void
    {
        $user = $this->userWithPermissions([], [$this->branchA]);

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.branches', [['id' => $this->branchA->id, 'code' => 'A', 'name' => $this->branchA->name]]);
    }
}
