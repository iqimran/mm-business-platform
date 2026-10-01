<?php

namespace Tests\Feature\Car;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Models\CarExpenseType;
use App\Modules\Car\Models\CarParty;
use App\Modules\Identity\Models\User;

class MasterDataTest extends CarTestCase
{
    private const ENDPOINTS = [
        'dealer' => '/api/v1/car-dealers',
        'party' => '/api/v1/car-parties',
        'expense_type' => '/api/v1/car-expense-types',
    ];

    private function admin(string $type): User
    {
        return $this->userWith(array_map(fn ($a) => "car.{$type}.{$a}", ['view', 'create', 'update', 'delete']));
    }

    public function test_unauthenticated_and_unpermitted_access_is_rejected(): void
    {
        foreach (self::ENDPOINTS as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }

        $nobody = $this->userWith([], [$this->branchA]);
        foreach (self::ENDPOINTS as $endpoint) {
            $this->actingAs($nobody)->getJson($endpoint)->assertForbidden();
            $this->actingAs($nobody)->postJson($endpoint, ['name' => 'X'])->assertForbidden();
        }

        $viewer = $this->userWith(['car.dealer.view']);
        $dealer = CarDealer::factory()->create();
        $this->actingAs($viewer)->getJson("/api/v1/car-dealers/{$dealer->id}")->assertOk();
        $this->actingAs($viewer)->putJson("/api/v1/car-dealers/{$dealer->id}", ['name' => 'Y'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/v1/car-dealers/{$dealer->id}")->assertForbidden();
    }

    public function test_dealer_crud_with_normalization_and_audit(): void
    {
        $admin = $this->admin('dealer');

        $id = $this->actingAs($admin)->postJson('/api/v1/car-dealers', [
            'name' => '  Rahim Motors ',
            'phone' => '+880 1700-000001',
            'email' => 'rahim@example.com',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Rahim Motors')
            ->assertJsonPath('data.phone', '+8801700000001')
            ->json('data.id');

        $this->actingAs($admin)->getJson('/api/v1/car-dealers?search=rahim')
            ->assertOk()->assertJsonPath('data.items.0.cars_count', 0);

        $this->actingAs($admin)->putJson("/api/v1/car-dealers/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAs($admin)->getJson('/api/v1/car-dealers?is_active=1')->assertJsonPath('data.pagination.total', 0);

        $this->actingAs($admin)->deleteJson("/api/v1/car-dealers/{$id}")->assertOk();
        $this->assertSame(
            ['car_dealer.created', 'car_dealer.updated', 'car_dealer.deleted'],
            AuditLog::orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_duplicate_dealers_are_rejected(): void
    {
        $admin = $this->admin('dealer');
        CarDealer::factory()->create(['name' => 'Rahim Motors', 'phone' => '+8801700000001']);

        $this->actingAs($admin)->postJson('/api/v1/car-dealers', ['name' => 'RAHIM motors', 'phone' => '+880-1700 000001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone' => 'This phone number is already registered.']);
        $this->actingAs($admin)->postJson('/api/v1/car-dealers', ['name' => 'New', 'phone' => 'call me', 'email' => 'bad'])
            ->assertJsonValidationErrors(['phone' => 'Enter a valid phone number.', 'email']);
        $this->actingAs($admin)->postJson('/api/v1/car-dealers', ['name' => '  '])
            ->assertJsonValidationErrors('name');
    }

    public function test_dealer_can_keep_its_own_name_and_phone_on_update(): void
    {
        $admin = $this->admin('dealer');
        $dealer = CarDealer::factory()->create(['name' => 'Rahim Motors', 'phone' => '+8801700000001']);

        $this->actingAs($admin)->putJson("/api/v1/car-dealers/{$dealer->id}", ['name' => 'Rahim Motors', 'phone' => '+8801700000001'])
            ->assertOk();
    }

    public function test_parties_allow_same_name_but_not_same_phone_or_national_id(): void
    {
        $admin = $this->admin('party');
        CarParty::factory()->create(['name' => 'Abdul Karim', 'phone' => '+8801800000001', 'national_id' => '1990123456']);

        $this->actingAs($admin)->postJson('/api/v1/car-parties', ['name' => 'Abdul Karim', 'phone' => '01800000002'])
            ->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/car-parties', ['name' => 'Other', 'phone' => '+880 1800 000001', 'national_id' => '1990 123 456'])
            ->assertJsonValidationErrors(['phone', 'national_id']);
    }

    public function test_expense_types_are_unique_case_insensitively(): void
    {
        $admin = $this->admin('expense_type');
        CarExpenseType::factory()->create(['name' => 'Paint']);

        $this->actingAs($admin)->postJson('/api/v1/car-expense-types', ['name' => 'PAINT'])
            ->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson('/api/v1/car-expense-types', ['name' => 'Tyres', 'description' => 'Tyre replacement'])
            ->assertCreated()->assertJsonPath('data.name', 'Tyres');
        $this->assertDatabaseHas('audit_logs', ['action' => 'car_expense_type.created']);
    }

    public function test_unknown_record_returns_not_found(): void
    {
        $this->actingAs($this->admin('party'))->getJson('/api/v1/car-parties/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    }
}
