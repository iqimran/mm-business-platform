<?php

namespace Tests\Feature\Administration;

use App\Modules\Administration\Models\Setting;
use App\Modules\Audit\Models\AuditLog;

class SettingAndAuditTest extends AdministrationTestCase
{
    public function test_settings_require_permissions(): void
    {
        $this->getJson('/api/v1/settings')->assertUnauthorized();

        $none = $this->userWith([]);
        $this->actingAs($none)->getJson('/api/v1/settings')->assertForbidden();

        $viewer = $this->userWith(['setting.view']);
        $this->actingAs($viewer)->getJson('/api/v1/settings')->assertOk();
        $this->actingAs($viewer)->putJson('/api/v1/settings/app.name', ['value' => 'X'])->assertForbidden();
    }

    public function test_admin_creates_and_updates_settings_with_audit(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson('/api/v1/settings/app.name', ['value' => 'MM Business', 'description' => 'Display name'])
            ->assertOk()->assertJsonPath('data.value', 'MM Business');
        $this->actingAs($admin)->putJson('/api/v1/settings/app.name', ['value' => 'MM Group'])
            ->assertOk()->assertJsonPath('data.description', 'Display name');

        $this->actingAs($admin)->getJson('/api/v1/settings/app.name')->assertOk()->assertJsonPath('data.value', 'MM Group');

        $logs = AuditLog::where('action', 'setting.updated')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNull($logs[0]->old_values);
        $this->assertSame(['key' => 'app.name', 'value' => 'MM Business'], $logs[1]->old_values);
        $this->assertSame(['key' => 'app.name', 'value' => 'MM Group'], $logs[1]->new_values);
    }

    public function test_setting_input_is_validated(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->putJson('/api/v1/settings/Bad Key', ['value' => 'x'])->assertNotFound();
        $this->actingAs($admin)->putJson('/api/v1/settings/app.name', [])->assertJsonValidationErrors('value');
        $this->actingAs($admin)->putJson('/api/v1/settings/app.blob', ['value' => str_repeat('x', 70000)])
            ->assertJsonValidationErrors('value');
        $this->actingAs($admin)->getJson('/api/v1/settings/app.missing')->assertNotFound();
        $this->assertSame(0, Setting::count());
    }

    public function test_audit_log_requires_permission(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
        $this->actingAs($this->userWith(['user.view']))->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_audit_log_is_scoped_to_accessible_branches(): void
    {
        $global = $this->superAdmin();
        $this->actingAs($global)->putJson("/api/v1/branches/{$this->branchA->id}", ['name' => 'A2'])->assertOk();
        $this->actingAs($global)->putJson("/api/v1/branches/{$this->branchB->id}", ['name' => 'B2'])->assertOk();
        $this->actingAs($global)->putJson('/api/v1/settings/app.name', ['value' => 'X'])->assertOk(); // no branch

        $this->actingAs($global)->getJson('/api/v1/audit-logs')
            ->assertOk()->assertJsonPath('data.pagination.total', 3);

        $branchAdmin = $this->branchAdmin();
        $items = $this->actingAs($branchAdmin)->getJson('/api/v1/audit-logs')->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('A', $items[0]['branch']['code']);
        $this->assertSame($global->id, $items[0]['user']['id']);
    }

    public function test_audit_log_can_be_filtered(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->putJson('/api/v1/settings/app.name', ['value' => 'X'])->assertOk();
        $this->actingAs($admin)->putJson("/api/v1/branches/{$this->branchA->id}", ['name' => 'A2'])->assertOk();

        $this->actingAs($admin)->getJson('/api/v1/audit-logs?action=setting.updated')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.action', 'setting.updated');
        $this->actingAs($admin)->getJson('/api/v1/audit-logs?from=2030-01-01&to=2020-01-01')
            ->assertJsonValidationErrors('to');
    }
}
