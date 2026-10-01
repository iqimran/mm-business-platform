<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Car\Models\CarParty;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use App\Modules\Restaurant\Models\RestaurantSupplier;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;

class CustomerSupplierTest extends RestaurantTestCase
{
    private const CUSTOMERS = '/api/v1/restaurant/customers';

    private const SUPPLIERS = '/api/v1/restaurant/suppliers';

    private function admin(string $type, array $branches = []): User
    {
        return $this->userWith(array_map(fn ($a) => "restaurant.{$type}.{$a}", ['view', 'create', 'update']), $branches);
    }

    public function test_unauthenticated_and_unpermitted_access_is_rejected(): void
    {
        $customer = RestaurantCustomer::factory()->create();
        $supplier = RestaurantSupplier::factory()->create();

        foreach ([self::CUSTOMERS, self::SUPPLIERS] as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
            $this->postJson($endpoint, ['name' => 'X'])->assertUnauthorized();
        }

        // Branch assignment alone grants nothing; car permissions grant nothing in the restaurant module.
        $nobody = $this->userWith(['car.party.view', 'car.party.create', 'car.dealer.view'], [$this->branchA]);
        foreach ([self::CUSTOMERS => $customer, self::SUPPLIERS => $supplier] as $endpoint => $record) {
            $this->actingAs($nobody)->getJson($endpoint)->assertForbidden();
            $this->actingAs($nobody)->getJson("{$endpoint}/{$record->id}")->assertForbidden();
            $this->actingAs($nobody)->postJson($endpoint, ['name' => 'X'])->assertForbidden();
            $this->actingAs($nobody)->putJson("{$endpoint}/{$record->id}", ['name' => 'Y'])->assertForbidden();
        }

        $viewer = $this->userWith(['restaurant.customer.view']);
        $this->actingAs($viewer)->getJson("/api/v1/restaurant/customers/{$customer->id}")->assertOk();
        $this->actingAs($viewer)->putJson("/api/v1/restaurant/customers/{$customer->id}", ['name' => 'Y'])->assertForbidden();
        $this->actingAs($viewer)->getJson(self::SUPPLIERS)->assertForbidden();
        $this->assertSame($customer->name, $customer->fresh()->name);
    }

    public function test_customers_and_suppliers_cannot_be_deleted(): void
    {
        $user = $this->userWith(array_keys(PermissionSeeder::PERMISSIONS));
        $customer = RestaurantCustomer::factory()->create();
        $supplier = RestaurantSupplier::factory()->create();

        $this->actingAs($user)->deleteJson(self::CUSTOMERS."/{$customer->id}")->assertMethodNotAllowed();
        $this->actingAs($user)->deleteJson(self::SUPPLIERS."/{$supplier->id}")->assertMethodNotAllowed();
        $this->assertModelExists($customer);
        $this->assertModelExists($supplier);
    }

    public function test_customer_create_read_update_with_normalization_and_audit(): void
    {
        $admin = $this->admin('customer');

        $id = $this->actingAs($admin)->postJson(self::CUSTOMERS, [
            'name' => '  Karim Uddin ',
            'phone' => '+880 1711-000001',
            'address' => ' Dhaka ',
            'notes' => '',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Karim Uddin')
            ->assertJsonPath('data.phone', '+8801711000001')
            ->assertJsonPath('data.address', 'Dhaka')
            ->assertJsonPath('data.notes', null)
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->actingAs($admin)->getJson(self::CUSTOMERS."/{$id}")->assertOk()->assertJsonPath('data.name', 'Karim Uddin');

        $this->actingAs($admin)->putJson(self::CUSTOMERS."/{$id}", ['is_active' => false, 'notes' => 'Regular'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.notes', 'Regular');

        $logs = AuditLog::orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['restaurant_customer.created', 'restaurant_customer.updated'], $logs->pluck('action')->all());
        $this->assertSameValues(['is_active' => true, 'notes' => null], $logs[1]->old_values);
        $this->assertSameValues(['is_active' => false, 'notes' => 'Regular'], $logs[1]->new_values);
        $this->assertSame($admin->id, $logs[1]->user_id);
    }

    public function test_customer_search_filter_and_pagination(): void
    {
        $admin = $this->admin('customer');
        RestaurantCustomer::factory()->create(['name' => 'Karim Uddin', 'phone' => '+8801711000001']);
        RestaurantCustomer::factory()->create(['name' => 'Rahima Begum', 'phone' => '+8801811000002', 'is_active' => false]);
        RestaurantCustomer::factory()->count(3)->create();

        $this->actingAs($admin)->getJson(self::CUSTOMERS.'?search=karim')
            ->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.name', 'Karim Uddin');
        $this->actingAs($admin)->getJson(self::CUSTOMERS.'?search=1811000')
            ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.name', 'Rahima Begum');
        $this->actingAs($admin)->getJson(self::CUSTOMERS.'?is_active=0')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($admin)->getJson(self::CUSTOMERS.'?is_active=1')->assertJsonPath('data.pagination.total', 4);

        $page = $this->actingAs($admin)->getJson(self::CUSTOMERS.'?per_page=2&page=2')->assertOk();
        $page->assertJsonPath('data.pagination.total', 5)->assertJsonPath('data.pagination.last_page', 3)->assertJsonCount(2, 'data.items');

        $this->actingAs($admin)->getJson(self::CUSTOMERS.'?is_active=maybe')->assertUnprocessable();
    }

    public function test_customer_validation_and_duplicates(): void
    {
        $admin = $this->admin('customer');
        $existing = RestaurantCustomer::factory()->create(['name' => 'Karim', 'phone' => '+8801711000001']);

        // Same phone in a different format is a duplicate; same name (another person) is allowed.
        $this->actingAs($admin)->postJson(self::CUSTOMERS, ['name' => 'Other', 'phone' => '+880-1711 000001'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'This phone number is already registered.']);
        $this->actingAs($admin)->postJson(self::CUSTOMERS, ['name' => 'Karim'])->assertCreated();

        $this->actingAs($admin)->postJson(self::CUSTOMERS, ['name' => '   ', 'phone' => 'call me'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone' => 'Enter a valid phone number.']);
        $this->actingAs($admin)->postJson(self::CUSTOMERS, ['name' => str_repeat('a', 151)])->assertJsonValidationErrors('name');

        // Updating a record keeps its own phone.
        $this->actingAs($admin)->putJson(self::CUSTOMERS."/{$existing->id}", ['phone' => '+8801711000001', 'name' => 'Karim U'])->assertOk();

        // Unknown fields (e.g. branch_id) are ignored: customers are shared by all branches.
        $this->actingAs($admin)->postJson(self::CUSTOMERS, ['name' => 'Branchless', 'branch_id' => $this->branchA->id])
            ->assertCreated()->assertJsonMissingPath('data.branch_id');

        $this->assertRejected(fn () => RestaurantCustomer::query()->insert(['id' => strtolower((string) Str::ulid()), 'name' => ' ', 'created_at' => now(), 'updated_at' => now()]), 'not_blank');
    }

    public function test_master_data_is_shared_by_all_branches(): void
    {
        $customer = RestaurantCustomer::factory()->create(['name' => 'Shared Customer']);
        $supplier = RestaurantSupplier::factory()->create(['name' => 'Shared Supplier']);
        $permissions = ['restaurant.customer.view', 'restaurant.customer.update', 'restaurant.supplier.view'];

        foreach ([[$this->branchA], [$this->branchB], []] as $branches) {
            $user = $this->userWith($permissions, $branches);
            $this->actingAs($user)->getJson(self::CUSTOMERS)->assertOk()->assertJsonPath('data.items.0.id', $customer->id);
            $this->actingAs($user)->getJson(self::SUPPLIERS."/{$supplier->id}")->assertOk();
            $this->actingAs($user)->putJson(self::CUSTOMERS."/{$customer->id}", ['notes' => 'ok'])->assertOk();
        }
    }

    public function test_restaurant_customers_are_isolated_from_car_parties(): void
    {
        $admin = $this->userWith(['restaurant.customer.create', 'car.party.view']);
        $this->actingAs($admin)->postJson(self::CUSTOMERS, ['name' => 'Food Lover', 'phone' => '+8801711000009'])->assertCreated();

        // A car party with the same phone is a different entity in a different module.
        CarParty::factory()->create(['phone' => '+8801711000009']);
        $this->actingAs($admin)->getJson('/api/v1/car-parties?search=Food')->assertJsonPath('data.pagination.total', 0);
        $this->assertSame(1, RestaurantCustomer::count());
    }

    public function test_supplier_crud_duplicates_and_audit(): void
    {
        $admin = $this->admin('supplier');

        $id = $this->actingAs($admin)->postJson(self::SUPPLIERS, [
            'name' => ' Fresh Foods Ltd ',
            'contact_person' => ' Abdul ',
            'phone' => '01700 000 111',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Fresh Foods Ltd')
            ->assertJsonPath('data.contact_person', 'Abdul')
            ->assertJsonPath('data.phone', '01700000111')
            ->json('data.id');

        $this->actingAs($admin)->postJson(self::SUPPLIERS, ['name' => 'FRESH foods ltd'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson(self::SUPPLIERS, ['name' => 'Other', 'phone' => '+01700000111'])->assertCreated();
        $this->actingAs($admin)->postJson(self::SUPPLIERS, ['name' => 'Third', 'phone' => '01700-000-111'])
            ->assertJsonValidationErrors(['phone' => 'This phone number is already registered.']);

        $this->actingAs($admin)->getJson(self::SUPPLIERS.'?search=abdul')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($admin)->putJson(self::SUPPLIERS."/{$id}", ['name' => 'Fresh Foods Ltd', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAs($admin)->getJson(self::SUPPLIERS.'?is_active=1')->assertJsonPath('data.pagination.total', 1);

        $this->assertSame(
            ['restaurant_supplier.created', 'restaurant_supplier.created', 'restaurant_supplier.updated'],
            AuditLog::orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertDatabaseMissing('restaurant_suppliers', ['name' => 'Third']);
    }
}
