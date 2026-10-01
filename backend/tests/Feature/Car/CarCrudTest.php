<?php

namespace Tests\Feature\Car;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealer;

class CarCrudTest extends CarTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branchA->id,
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'model_year' => 2018,
            'chassis_number' => 'nze141 - 1000001',
            'registration_number' => 'dhaka metro  ga 12-3456',
            'mileage_km' => 45000,
        ], $overrides);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/cars')->assertUnauthorized();
        $this->postJson('/api/v1/cars', $this->payload())->assertUnauthorized();
    }

    public function test_car_permissions_are_required(): void
    {
        $user = $this->userWith([], [$this->branchA]);
        $car = Car::factory()->create(['branch_id' => $this->branchA->id]);

        $this->actingAs($user)->getJson('/api/v1/cars')->assertForbidden();
        $this->actingAs($user)->getJson("/api/v1/cars/{$car->id}")->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/cars', $this->payload())->assertForbidden();
        $this->actingAs($user)->putJson("/api/v1/cars/{$car->id}", ['color' => 'Red'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/v1/cars/{$car->id}")->assertForbidden();
    }

    public function test_manager_creates_car_with_normalized_identifiers(): void
    {
        $user = $this->managerA();
        $dealer = CarDealer::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/cars', $this->payload(['dealer_id' => $dealer->id]));

        $response->assertCreated()
            ->assertJsonPath('data.chassis_number', 'NZE141-1000001')
            ->assertJsonPath('data.registration_number', 'DHAKA METRO GA 12-3456')
            ->assertJsonPath('data.status', 'PURCHASED')
            ->assertJsonPath('data.branch.code', 'A')
            ->assertJsonPath('data.dealer.id', $dealer->id);

        $log = AuditLog::where('action', 'car.created')->first();
        $this->assertSame($this->branchA->id, $log->branch_id);
        $this->assertSame('NZE141-1000001', $log->new_values['chassis_number']);
    }

    public function test_car_input_is_validated(): void
    {
        $user = $this->managerA();
        $inactiveDealer = CarDealer::factory()->create(['is_active' => false]);

        $this->actingAs($user)->postJson('/api/v1/cars', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id', 'brand', 'model', 'chassis_number']);

        $this->actingAs($user)->postJson('/api/v1/cars', $this->payload([
            'model_year' => 1800,
            'mileage_km' => -5,
            'dealer_id' => $inactiveDealer->id,
            'status' => 'SOLD',
            'brand' => '   ',
        ]))->assertJsonValidationErrors(['model_year', 'mileage_km', 'dealer_id', 'status', 'brand']);
    }

    public function test_duplicate_identifiers_are_rejected_after_normalization(): void
    {
        $user = $this->managerA();
        Car::factory()->create([
            'branch_id' => $this->branchB->id,
            'chassis_number' => 'NZE141-1000001',
            'engine_number' => 'ENG1',
            'registration_number' => 'DHAKA METRO GA 12-3456',
        ]);

        $this->actingAs($user)->postJson('/api/v1/cars', $this->payload(['engine_number' => 'eng 1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'chassis_number' => 'A car with this chassis number already exists.',
                'engine_number',
                'registration_number',
            ]);
    }

    public function test_car_cannot_be_created_in_inaccessible_branch(): void
    {
        $this->actingAs($this->managerA())->postJson('/api/v1/cars', $this->payload(['branch_id' => $this->branchB->id]))
            ->assertJsonValidationErrors('branch_id');

        $this->assertDatabaseCount('cars', 0);
    }

    public function test_listing_is_scoped_to_accessible_branches_and_filterable(): void
    {
        Car::factory()->create(['branch_id' => $this->branchA->id, 'brand' => 'Toyota']);
        Car::factory()->create(['branch_id' => $this->branchA->id, 'brand' => 'Honda']);
        Car::factory()->create(['branch_id' => $this->branchB->id, 'brand' => 'Toyota']);

        $this->actingAs($this->managerA())->getJson('/api/v1/cars')
            ->assertOk()->assertJsonPath('data.pagination.total', 2);
        $this->actingAs($this->managerA())->getJson('/api/v1/cars?search=toyota')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        // Asking for another branch's cars returns nothing rather than leaking them.
        $this->actingAs($this->managerA())->getJson("/api/v1/cars?branch_id={$this->branchB->id}")
            ->assertOk()->assertJsonPath('data.pagination.total', 0);

        $global = $this->userWith(['car.view', 'branch.access_all']);
        $this->actingAs($global)->getJson('/api/v1/cars')->assertJsonPath('data.pagination.total', 3);
        $this->actingAs($global)->getJson('/api/v1/cars?status=SOLD')->assertJsonPath('data.pagination.total', 0);
        $this->actingAs($global)->getJson('/api/v1/cars?status=FLYING')->assertJsonValidationErrors('status');
    }

    public function test_cross_branch_car_access_is_blocked(): void
    {
        $user = $this->managerA();
        $foreign = Car::factory()->create(['branch_id' => $this->branchB->id]);

        $this->actingAs($user)->getJson("/api/v1/cars/{$foreign->id}")->assertForbidden();
        $this->actingAs($user)->putJson("/api/v1/cars/{$foreign->id}", ['color' => 'Red'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/v1/cars/{$foreign->id}")->assertForbidden();
        $this->assertModelExists($foreign);
    }

    public function test_manager_updates_car_and_changes_are_audited(): void
    {
        $user = $this->managerA();
        $car = Car::factory()->create(['branch_id' => $this->branchA->id, 'color' => 'White']);

        $this->actingAs($user)->putJson("/api/v1/cars/{$car->id}", ['color' => 'Pearl White', 'mileage_km' => 50000])
            ->assertOk()
            ->assertJsonPath('data.color', 'Pearl White');

        $log = AuditLog::where('action', 'car.updated')->first();
        $this->assertSame('White', $log->old_values['color']);
        $this->assertSame(['color' => 'Pearl White', 'mileage_km' => 50000], $log->new_values);
    }

    public function test_status_cannot_be_changed_through_update(): void
    {
        $user = $this->managerA();
        $car = Car::factory()->create(['branch_id' => $this->branchA->id]);

        $this->actingAs($user)->putJson("/api/v1/cars/{$car->id}", ['status' => 'SOLD'])
            ->assertJsonValidationErrors(['status' => 'The car status cannot be changed here.']);
        $this->assertSame(CarStatus::Purchased, $car->fresh()->status);
    }

    public function test_car_transfer_requires_access_to_the_target_branch(): void
    {
        $car = Car::factory()->create(['branch_id' => $this->branchA->id]);

        $this->actingAs($this->managerA())->putJson("/api/v1/cars/{$car->id}", ['branch_id' => $this->branchB->id])
            ->assertJsonValidationErrors('branch_id');

        $both = $this->userWith(self::CAR_PERMISSIONS, [$this->branchA, $this->branchB]);
        $this->actingAs($both)->putJson("/api/v1/cars/{$car->id}", ['branch_id' => $this->branchB->id])
            ->assertOk()->assertJsonPath('data.branch.code', 'B');
    }

    public function test_car_can_be_deleted_unless_sold(): void
    {
        $user = $this->managerA();
        $car = Car::factory()->create(['branch_id' => $this->branchA->id]);
        $sold = Car::factory()->create(['branch_id' => $this->branchA->id]);
        $sold->forceFill(['status' => CarStatus::Sold])->save();

        $this->actingAs($user)->deleteJson("/api/v1/cars/{$car->id}")->assertOk();
        $this->assertModelMissing($car);
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.deleted', 'entity_id' => $car->id, 'branch_id' => $this->branchA->id]);

        $this->actingAs($user)->deleteJson("/api/v1/cars/{$sold->id}")->assertConflict();
        $this->assertModelExists($sold);
    }

    public function test_dealer_linked_to_cars_cannot_be_deleted(): void
    {
        $dealer = CarDealer::factory()->create();
        Car::factory()->create(['branch_id' => $this->branchA->id, 'dealer_id' => $dealer->id]);
        $admin = $this->userWith(['car.dealer.delete']);

        $this->actingAs($admin)->deleteJson("/api/v1/car-dealers/{$dealer->id}")->assertConflict();
        $this->assertModelExists($dealer);
    }
}
