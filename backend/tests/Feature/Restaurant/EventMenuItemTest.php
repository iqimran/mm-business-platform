<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Restaurant\Models\EventMenuItem;
use App\Modules\Restaurant\Models\MenuItem;

/**
 * Event menu items: master data for hall booking food packages, without prices.
 */
class EventMenuItemTest extends RestaurantTestCase
{
    private const URL = '/api/v1/restaurant/event-menu-items';

    public function test_crud_without_prices_and_audit(): void
    {
        $admin = $this->userWith(array_map(fn ($a) => "restaurant.event_menu.{$a}", ['view', 'create', 'update', 'delete']));

        $id = $this->actingAs($admin)->postJson(self::URL, ['name' => ' Kacchi Biryani ', 'description' => 'Mutton', 'price' => '350'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Kacchi Biryani')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.price')
            ->json('data.id');

        $this->actingAs($admin)->getJson(self::URL.'?search=kacchi')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($admin)->putJson(self::URL."/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAs($admin)->getJson(self::URL.'?is_active=1')->assertJsonPath('data.pagination.total', 0);
        $this->actingAs($admin)->deleteJson(self::URL."/{$id}")->assertOk();

        $this->assertSame(
            ['restaurant_event_menu_item.created', 'restaurant_event_menu_item.updated', 'restaurant_event_menu_item.deleted'],
            AuditLog::orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertArrayNotHasKey('price', AuditLog::where('action', 'restaurant_event_menu_item.created')->sole()->new_values);
    }

    public function test_validation_and_duplicates(): void
    {
        $admin = $this->userWith(['restaurant.event_menu.create', 'restaurant.event_menu.update']);
        EventMenuItem::factory()->create(['name' => 'Polao']);

        $this->actingAs($admin)->postJson(self::URL, ['name' => 'POLAO '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson(self::URL, ['name' => ' '])->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson(self::URL, ['name' => str_repeat('x', 151)])->assertJsonValidationErrors('name');
        $this->assertRejected(fn () => EventMenuItem::factory()->create(['name' => 'polao']), 'unique');
        $this->assertSame(1, EventMenuItem::count());
    }

    public function test_permissions(): void
    {
        $item = EventMenuItem::factory()->create();

        $this->getJson(self::URL)->assertUnauthorized();
        // Food menu permissions do not cover the event menu.
        $foodMenu = $this->userWith(['restaurant.menu.view', 'restaurant.menu.create', 'restaurant.menu.delete'], [$this->branchA]);
        $this->actingAs($foodMenu)->getJson(self::URL)->assertForbidden();
        $this->actingAs($foodMenu)->postJson(self::URL, ['name' => 'X'])->assertForbidden();
        $this->actingAs($foodMenu)->deleteJson(self::URL."/{$item->id}")->assertForbidden();

        $viewer = $this->userWith(['restaurant.event_menu.view']);
        $this->actingAs($viewer)->getJson(self::URL."/{$item->id}")->assertOk();
        $this->actingAs($viewer)->putJson(self::URL."/{$item->id}", ['name' => 'Y'])->assertForbidden();
        $this->assertModelExists($item);
    }

    public function test_event_menu_and_food_menu_are_separate(): void
    {
        $admin = $this->userWith(['restaurant.event_menu.view', 'restaurant.menu.view']);
        EventMenuItem::factory()->create(['name' => 'Roast']);
        MenuItem::factory()->create(['name' => 'Chicken Fry']);

        $this->actingAs($admin)->getJson(self::URL)->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.name', 'Roast');
        $this->actingAs($admin)->getJson('/api/v1/restaurant/menu-items')->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.name', 'Chicken Fry');
    }
}
