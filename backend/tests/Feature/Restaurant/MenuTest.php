<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\MenuCategory;
use App\Modules\Restaurant\Models\MenuItem;
use Illuminate\Support\Facades\DB;

class MenuTest extends RestaurantTestCase
{
    private const CATEGORIES = '/api/v1/restaurant/menu-categories';

    private const ITEMS = '/api/v1/restaurant/menu-items';

    private function manager(): User
    {
        $permissions = [];
        foreach (['menu_category', 'menu'] as $type) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                $permissions[] = "restaurant.{$type}.{$action}";
            }
        }

        return $this->userWith($permissions, [$this->branchA]);
    }

    public function test_unauthenticated_and_unpermitted_access_is_rejected(): void
    {
        $item = MenuItem::factory()->create();
        $category = $item->category;

        foreach ([self::CATEGORIES, self::ITEMS] as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }

        $nobody = $this->userWith([], [$this->branchA]);
        $this->actingAs($nobody)->getJson(self::CATEGORIES)->assertForbidden();
        $this->actingAs($nobody)->getJson(self::ITEMS)->assertForbidden();
        $this->actingAs($nobody)->postJson(self::CATEGORIES, ['name' => 'X'])->assertForbidden();
        $this->actingAs($nobody)->deleteJson(self::ITEMS."/{$item->id}")->assertForbidden();

        // Menu viewers cannot change prices or delete; category permissions do not cover items.
        $viewer = $this->userWith(['restaurant.menu.view', 'restaurant.menu_category.update', 'restaurant.menu_category.delete']);
        $this->actingAs($viewer)->getJson(self::ITEMS."/{$item->id}")->assertOk();
        $this->actingAs($viewer)->putJson(self::ITEMS."/{$item->id}", ['price' => '1.00'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson(self::ITEMS."/{$item->id}")->assertForbidden();
        $this->actingAs($viewer)->getJson(self::CATEGORIES."/{$category->id}")->assertForbidden();

        $this->assertModelExists($item);
        $this->assertNotSame(100, $item->fresh()->price_minor);
    }

    public function test_category_crud_ordering_and_audit(): void
    {
        $manager = $this->manager();

        $drinks = $this->actingAs($manager)->postJson(self::CATEGORIES, ['name' => ' Drinks ', 'sort_order' => 2])
            ->assertCreated()->assertJsonPath('data.name', 'Drinks')->assertJsonPath('data.items_count', 0)->json('data.id');
        $this->actingAs($manager)->postJson(self::CATEGORIES, ['name' => 'Starters', 'sort_order' => 1, 'description' => 'Before the meal'])
            ->assertCreated()->assertJsonPath('data.is_active', true);

        $this->actingAs($manager)->getJson(self::CATEGORIES)
            ->assertOk()->assertJsonPath('data.items.0.name', 'Starters')->assertJsonPath('data.items.1.name', 'Drinks');
        $this->actingAs($manager)->getJson(self::CATEGORIES.'?search=dri')->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($manager)->putJson(self::CATEGORIES."/{$drinks}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAs($manager)->getJson(self::CATEGORIES.'?is_active=0')->assertJsonPath('data.items.0.id', $drinks);

        $this->actingAs($manager)->deleteJson(self::CATEGORIES."/{$drinks}")->assertOk();
        $this->assertDatabaseMissing('restaurant_menu_categories', ['id' => $drinks]);

        $this->assertSame(
            ['restaurant_menu_category.created', 'restaurant_menu_category.created', 'restaurant_menu_category.updated', 'restaurant_menu_category.deleted'],
            AuditLog::orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(['is_active' => true], AuditLog::where('action', 'restaurant_menu_category.updated')->first()->old_values);
    }

    public function test_category_validation_and_duplicates(): void
    {
        $manager = $this->manager();
        $existing = MenuCategory::factory()->create(['name' => 'Drinks']);

        $this->actingAs($manager)->postJson(self::CATEGORIES, ['name' => '  dRINKS '])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($manager)->postJson(self::CATEGORIES, ['name' => ''])->assertJsonValidationErrors('name');
        $this->actingAs($manager)->postJson(self::CATEGORIES, ['name' => 'X', 'sort_order' => 10000])->assertJsonValidationErrors('sort_order');
        $this->actingAs($manager)->postJson(self::CATEGORIES, ['name' => 'X', 'sort_order' => -1])->assertJsonValidationErrors('sort_order');
        $this->actingAs($manager)->putJson(self::CATEGORIES."/{$existing->id}", ['name' => 'drinks'])->assertOk();

        // The database enforces case-insensitive uniqueness even if validation is bypassed.
        $this->assertRejected(fn () => MenuCategory::factory()->create(['name' => 'DRINKS']), 'unique');
        $this->assertSame(1, MenuCategory::count());
    }

    public function test_category_with_items_cannot_be_deleted(): void
    {
        $manager = $this->manager();
        $item = MenuItem::factory()->create();

        $this->actingAs($manager)->deleteJson(self::CATEGORIES."/{$item->category_id}")
            ->assertStatus(409)->assertJsonPath('success', false);
        $this->assertModelExists($item->category);
        $this->assertSame(0, AuditLog::count());

        $this->actingAs($manager)->getJson(self::CATEGORIES."/{$item->category_id}")->assertJsonPath('data.items_count', 1);

        // Database-level protection as well.
        $this->assertRejected(fn () => DB::table('restaurant_menu_categories')->where('id', $item->category_id)->delete(), 'foreign key');
    }

    public function test_menu_item_crud_with_price_in_minor_units_and_audit(): void
    {
        $manager = $this->manager();
        $category = MenuCategory::factory()->create(['name' => 'Rice']);

        $id = $this->actingAs($manager)->postJson(self::ITEMS, [
            'category_id' => $category->id,
            'name' => ' Kacchi Biryani ',
            'description' => 'Mutton',
            'price' => '350.5',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Kacchi Biryani')
            ->assertJsonPath('data.price', '350.50')
            ->assertJsonPath('data.category.name', 'Rice')
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');
        $this->assertSame(35050, MenuItem::find($id)->price_minor);

        $this->actingAs($manager)->putJson(self::ITEMS."/{$id}", ['price' => 380, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.price', '380.00')->assertJsonPath('data.is_active', false);

        $update = AuditLog::where('action', 'restaurant_menu_item.updated')->sole();
        $this->assertSameValues(['price_minor' => 35050, 'is_active' => true], $update->old_values);
        $this->assertSameValues(['price_minor' => 38000, 'is_active' => false], $update->new_values);

        $this->actingAs($manager)->getJson(self::ITEMS."/{$id}")->assertOk()->assertJsonPath('data.price', '380.00');
        $this->actingAs($manager)->deleteJson(self::ITEMS."/{$id}")->assertOk();
        $this->assertDatabaseMissing('restaurant_menu_items', ['id' => $id]);
        $this->assertSame(
            ['restaurant_menu_item.created', 'restaurant_menu_item.updated', 'restaurant_menu_item.deleted'],
            AuditLog::orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_menu_item_price_validation(): void
    {
        $manager = $this->manager();
        $category = MenuCategory::factory()->create();
        $item = MenuItem::factory()->for($category, 'category')->create(['price_minor' => 10000]);

        foreach ([12.5, '0', '0.00', '-5', '1.234', 'abc', '1,000', '1000000000000'] as $price) {
            $this->actingAs($manager)->postJson(self::ITEMS, ['category_id' => $category->id, 'name' => 'Tea', 'price' => $price])
                ->assertUnprocessable()->assertJsonValidationErrors('price');
        }
        $this->actingAs($manager)->postJson(self::ITEMS, ['category_id' => $category->id, 'name' => 'Tea'])
            ->assertJsonValidationErrors('price');
        $this->actingAs($manager)->putJson(self::ITEMS."/{$item->id}", ['price' => 99.99])->assertJsonValidationErrors('price');

        $this->assertSame(10000, $item->fresh()->price_minor);
        $this->assertRejected(fn () => DB::table('restaurant_menu_items')->where('id', $item->id)->update(['price_minor' => 0]), 'price_check');
        $this->assertSame(1, MenuItem::count());
    }

    public function test_menu_item_category_relationship_rules(): void
    {
        $manager = $this->manager();
        $rice = MenuCategory::factory()->create(['name' => 'Rice']);
        $drinks = MenuCategory::factory()->create(['name' => 'Drinks']);
        $archived = MenuCategory::factory()->create(['name' => 'Archived', 'is_active' => false]);
        $biryani = MenuItem::factory()->for($rice, 'category')->create(['name' => 'Biryani']);
        $legacy = MenuItem::factory()->for($archived, 'category')->create(['name' => 'Old dish']);

        // Category is required, must exist and must be active for new items.
        $this->actingAs($manager)->postJson(self::ITEMS, ['name' => 'Tea', 'price' => '20'])->assertJsonValidationErrors('category_id');
        $this->actingAs($manager)->postJson(self::ITEMS, ['category_id' => '01JAAAAAAAAAAAAAAAAAAAAAAA', 'name' => 'Tea', 'price' => '20'])
            ->assertJsonValidationErrors(['category_id' => 'Select an active menu category.']);
        $this->actingAs($manager)->postJson(self::ITEMS, ['category_id' => $archived->id, 'name' => 'Tea', 'price' => '20'])
            ->assertJsonValidationErrors('category_id');

        // Names are unique per category (case-insensitive) but may repeat across categories.
        $this->actingAs($manager)->postJson(self::ITEMS, ['category_id' => $rice->id, 'name' => ' BIRYANI ', 'price' => '20'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name' => 'A menu item with this name already exists in the category.']);
        $drinksBiryani = $this->actingAs($manager)->postJson(self::ITEMS, ['category_id' => $drinks->id, 'name' => 'Biryani', 'price' => '20'])
            ->assertCreated()->json('data.id');

        // Moving an item into a category that already has that name is a validation error, not a server error.
        $this->actingAs($manager)->putJson(self::ITEMS."/{$drinksBiryani}", ['category_id' => $rice->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        // Items may not be moved into an inactive category, but items already in one can still be edited.
        $this->actingAs($manager)->putJson(self::ITEMS."/{$biryani->id}", ['category_id' => $archived->id])->assertJsonValidationErrors('category_id');
        $this->actingAs($manager)->putJson(self::ITEMS."/{$legacy->id}", ['category_id' => $archived->id, 'price' => '75'])->assertOk();
        $this->actingAs($manager)->putJson(self::ITEMS."/{$legacy->id}", ['category_id' => $drinks->id])
            ->assertOk()->assertJsonPath('data.category.name', 'Drinks');

        // Filter by category.
        $this->actingAs($manager)->getJson(self::ITEMS."?category_id={$rice->id}")
            ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $biryani->id);
        $this->actingAs($manager)->getJson(self::ITEMS."?category_id={$drinks->id}&search=old")->assertJsonPath('data.pagination.total', 1);

        $this->assertRejected(fn () => MenuItem::factory()->for($rice, 'category')->create(['name' => 'biryani']), 'unique');
    }

    public function test_menu_listing_is_paginated_without_n_plus_one(): void
    {
        $manager = $this->manager();
        MenuItem::factory()->count(30)->create();

        DB::enableQueryLog();
        $this->actingAs($manager)->getJson(self::ITEMS.'?per_page=25')
            ->assertOk()->assertJsonCount(25, 'data.items')->assertJsonPath('data.pagination.total', 30)
            ->assertJsonStructure(['data' => ['items' => [['id', 'name', 'price', 'is_active', 'category' => ['id', 'name']]]]]);
        $menuQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'restaurant_menu'));
        $this->assertLessThanOrEqual(3, $menuQueries->count());
    }

    public function test_menu_is_shared_by_all_branches(): void
    {
        $item = MenuItem::factory()->create(['price_minor' => 15000]);

        foreach ([[$this->branchA], [$this->branchB], []] as $branches) {
            $user = $this->userWith(['restaurant.menu.view', 'restaurant.menu_category.view'], $branches);
            $this->actingAs($user)->getJson(self::ITEMS)->assertOk()
                ->assertJsonPath('data.items.0.id', $item->id)->assertJsonPath('data.items.0.price', '150.00');
            $this->actingAs($user)->getJson(self::CATEGORIES."/{$item->category_id}")->assertOk();
        }
    }
}
