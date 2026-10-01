<?php

namespace Tests\Feature\Car;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Models\CarPurchase;

class CarPurchaseTest extends CarTestCase
{
    private Car $car;

    private CarDealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->car = Car::factory()->create(['branch_id' => $this->branchA->id]);
        $this->dealer = CarDealer::factory()->create();
    }

    private function purchase(array $overrides = [], ?Car $car = null)
    {
        $car ??= $this->car;

        return $this->postJson("/api/v1/cars/{$car->id}/purchases", array_merge([
            'dealer_id' => $this->dealer->id,
            'purchase_date' => '2026-09-01',
            'amount' => '700000.00',
            'reference' => 'INV-001',
        ], $overrides));
    }

    public function test_records_purchase_with_exact_amount_and_audit(): void
    {
        $user = $this->financeA();

        $this->actingAs($user)->purchase()
            ->assertCreated()
            ->assertJsonPath('data.amount', '700000.00')
            ->assertJsonPath('data.dealer.id', $this->dealer->id)
            ->assertJsonPath('data.purchase_date', '2026-09-01')
            ->assertJsonPath('data.is_reversed', false);

        $purchase = CarPurchase::first();
        $this->assertSame(70000000, $purchase->amount_minor);
        $this->assertSame($this->branchA->id, $purchase->branch_id);
        $this->assertSame($user->id, $purchase->recorded_by);
        $this->assertSame($this->dealer->id, $this->car->fresh()->dealer_id, 'car dealer follows the purchase');

        $log = AuditLog::where('action', 'car.purchase_recorded')->first();
        $this->assertSame($this->branchA->id, $log->branch_id);
        $this->assertSame('700000.00', $log->new_values['amount']);

        $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/purchase")
            ->assertOk()
            ->assertJsonPath('data.active.amount', '700000.00')
            ->assertJsonPath('data.costs', ['purchase_cost' => '700000.00', 'expenses_total' => '0.00', 'total_investment' => '700000.00']);
    }

    public function test_purchase_amount_and_input_are_validated(): void
    {
        $user = $this->financeA();

        foreach (['0', '-5', 'abc', '1.234', '1e5', 1.5, '', '1000000000000'] as $amount) {
            $this->actingAs($user)->purchase(['amount' => $amount])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount');
        }

        $inactive = CarDealer::factory()->create(['is_active' => false]);
        $this->actingAs($user)->purchase([
            'dealer_id' => $inactive->id,
            'purchase_date' => now()->addDay()->toDateString(),
        ])->assertJsonValidationErrors(['dealer_id', 'purchase_date']);

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/purchases", [])
            ->assertJsonValidationErrors(['dealer_id', 'purchase_date', 'amount']);

        $this->assertDatabaseCount('car_purchases', 0);
    }

    public function test_only_one_active_purchase_per_car(): void
    {
        $user = $this->financeA();
        $this->actingAs($user)->purchase()->assertCreated();

        $this->actingAs($user)->purchase(['amount' => '650000'])
            ->assertConflict()
            ->assertJson(['success' => false]);

        $this->assertSame(1, CarPurchase::count());
    }

    public function test_correction_reverses_original_and_records_new_purchase(): void
    {
        $user = $this->financeA();
        $originalId = $this->actingAs($user)->purchase()->json('data.id');

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/purchases/{$originalId}/reverse", ['reason' => 'Wrong amount entered'])
            ->assertOk()
            ->assertJsonPath('data.is_reversed', true)
            ->assertJsonPath('data.reversal_reason', 'Wrong amount entered')
            ->assertJsonPath('data.reversed_by.id', $user->id);

        $this->actingAs($user)->purchase(['amount' => '690000.50'])->assertCreated();

        $response = $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/purchase")->assertOk();
        $response->assertJsonPath('data.active.amount', '690000.50')
            ->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.costs.purchase_cost', '690000.50');

        // Original is preserved unchanged, only flagged.
        $original = CarPurchase::find($originalId);
        $this->assertSame(70000000, $original->amount_minor);
        $this->assertTrue($original->isReversed());
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.purchase_reversed', 'entity_id' => $originalId]);
    }

    public function test_reversal_requires_reason_and_happens_once(): void
    {
        $user = $this->financeA();
        $id = $this->actingAs($user)->purchase()->json('data.id');
        $url = "/api/v1/cars/{$this->car->id}/purchases/{$id}/reverse";

        $this->actingAs($user)->postJson($url, ['reason' => '  '])->assertJsonValidationErrors('reason');
        $this->actingAs($user)->postJson($url, ['reason' => 'Duplicate entry'])->assertOk();
        $this->actingAs($user)->postJson($url, ['reason' => 'Duplicate entry'])->assertConflict();
    }

    public function test_sold_car_purchase_cannot_be_recorded_or_reversed(): void
    {
        $user = $this->financeA();
        $id = $this->actingAs($user)->purchase()->json('data.id');
        $this->car->forceFill(['status' => CarStatus::Sold])->save();

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/purchases/{$id}/reverse", ['reason' => 'Try reverse'])
            ->assertConflict();

        $other = Car::factory()->create(['branch_id' => $this->branchA->id]);
        $other->forceFill(['status' => CarStatus::Sold])->save();
        $this->actingAs($user)->purchase([], $other)->assertConflict();
    }

    public function test_permissions_are_enforced(): void
    {
        $this->getJson("/api/v1/cars/{$this->car->id}/purchase")->assertUnauthorized();
        $this->purchase()->assertUnauthorized();

        $viewer = $this->userWith(['car.view', 'car.purchase.view'], [$this->branchA]);
        $this->actingAs($viewer)->getJson("/api/v1/cars/{$this->car->id}/purchase")->assertOk();
        $this->actingAs($viewer)->purchase()->assertForbidden();

        $id = $this->actingAs($this->financeA())->purchase()->json('data.id');
        $this->actingAs($viewer)->postJson("/api/v1/cars/{$this->car->id}/purchases/{$id}/reverse", ['reason' => 'Not allowed'])
            ->assertForbidden();

        $carOnly = $this->managerA();
        $this->actingAs($carOnly)->getJson("/api/v1/cars/{$this->car->id}/purchase")->assertForbidden();
    }

    public function test_cross_branch_purchase_access_is_blocked(): void
    {
        $foreign = Car::factory()->create(['branch_id' => $this->branchB->id]);
        $purchase = CarPurchase::create([
            'car_id' => $foreign->id, 'branch_id' => $this->branchB->id, 'dealer_id' => $this->dealer->id,
            'purchase_date' => '2026-09-01', 'amount_minor' => 100, 'recorded_by' => $this->financeA()->id,
        ]);
        $user = $this->financeA();

        $this->actingAs($user)->getJson("/api/v1/cars/{$foreign->id}/purchase")->assertForbidden();
        $this->actingAs($user)->purchase([], $foreign)->assertForbidden();
        $this->actingAs($user)->postJson("/api/v1/cars/{$foreign->id}/purchases/{$purchase->id}/reverse", ['reason' => 'Cross branch'])
            ->assertForbidden();
        $this->assertFalse($purchase->fresh()->isReversed());
    }

    public function test_purchase_must_belong_to_car_in_url(): void
    {
        $user = $this->financeA();
        $id = $this->actingAs($user)->purchase()->json('data.id');
        $other = Car::factory()->create(['branch_id' => $this->branchA->id]);

        $this->actingAs($user)->postJson("/api/v1/cars/{$other->id}/purchases/{$id}/reverse", ['reason' => 'Wrong car'])
            ->assertNotFound();
    }

    public function test_car_with_financial_records_cannot_be_deleted_or_moved(): void
    {
        $this->actingAs($this->financeA())->purchase()->assertCreated();
        $manager = $this->userWith(self::CAR_PERMISSIONS, [$this->branchA, $this->branchB]);

        $this->actingAs($manager)->deleteJson("/api/v1/cars/{$this->car->id}")->assertConflict();
        $this->actingAs($manager)->putJson("/api/v1/cars/{$this->car->id}", ['branch_id' => $this->branchB->id])
            ->assertJsonValidationErrors('branch_id');

        $this->assertModelExists($this->car);
        $this->assertSame($this->branchA->id, $this->car->fresh()->branch_id);
    }
}
