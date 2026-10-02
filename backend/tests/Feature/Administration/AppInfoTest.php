<?php

namespace Tests\Feature\Administration;

use App\Modules\Administration\Models\Setting;

class AppInfoTest extends AdministrationTestCase
{
    public function test_app_name_is_public_and_follows_the_setting(): void
    {
        // Falls back to the configured name.
        $this->getJson('/api/v1/app-info')->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => ['name' => config('app.name')],
        ]);

        // Saving the setting changes the name everywhere (login page, sidebar, documents fallback).
        $this->actingAs($this->superAdmin())->putJson('/api/v1/settings/app.name', ['value' => '  MM Business POS Automation '])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/app-info')->assertOk()->assertJsonPath('data.name', 'MM Business POS Automation');
    }

    public function test_only_the_name_is_exposed(): void
    {
        Setting::create(['key' => 'app.secret_thing', 'value' => 'hidden']);
        Setting::create(['key' => 'business_profile.car', 'value' => ['name' => 'MM Motors', 'phone' => '017']]);

        $response = $this->getJson('/api/v1/app-info')->assertOk();
        $this->assertSame(['name'], array_keys($response->json('data')));
        $this->assertStringNotContainsString('hidden', $response->getContent());
        $this->assertStringNotContainsString('MM Motors', $response->getContent());
    }

    public function test_blank_setting_falls_back_to_the_configured_name(): void
    {
        Setting::create(['key' => 'app.name', 'value' => '   ']);

        $this->getJson('/api/v1/app-info')->assertJsonPath('data.name', config('app.name'));
    }
}
