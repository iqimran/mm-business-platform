<?php

namespace Tests\Feature\Car;

use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarParty;
use App\Modules\Car\Models\CarPurchase;

class DealerPaymentTest extends CarTestCase
{
    private Car $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->car = $this->purchasedCar('700000');
    }

    private function payDealer(string $amount, ?Car $car = null)
    {
        $car ??= $this->car;

        return $this->postJson("/api/v1/cars/{$car->id}/dealer-payments", [
            'payment_date' => '2026-09-02',
            'amount' => $amount,
            'method' => 'cash',
        ]);
    }

    public function test_partial_multiple_and_full_dealer_payments(): void
    {
        $user = $this->salesA();

        $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/dealer-payments")
            ->assertJsonPath('data.dealer', ['purchase_amount' => '700000.00', 'paid' => '0.00', 'payable' => '700000.00', 'is_settled' => false]);

        $this->actingAs($user)->payDealer('500000')->assertCreated()->assertJsonPath('data.dealer.payable', '200000.00');
        $this->actingAs($user)->payDealer('150000')->assertJsonPath('data.dealer.payable', '50000.00');
        $this->actingAs($user)->payDealer('50000')->assertJsonPath('data.dealer.payable', '0.00')->assertJsonPath('data.dealer.is_settled', true);

        $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/dealer-payments")->assertJsonCount(3, 'data.items');
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.dealer_payment_recorded', 'branch_id' => $this->branchA->id]);
    }

    public function test_dealer_overpayment_is_rejected(): void
    {
        $user = $this->salesA();
        $this->actingAs($user)->payDealer('650000')->assertCreated();

        $this->actingAs($user)->payDealer('50000.01')
            ->assertJsonValidationErrors(['amount' => 'The amount exceeds the remaining dealer payable of 50000.00.']);
    }

    public function test_dealer_payment_requires_an_active_purchase(): void
    {
        $noPurchase = Car::factory()->create(['branch_id' => $this->branchA->id]);

        $this->actingAs($this->salesA())->payDealer('100', $noPurchase)->assertConflict();
    }

    public function test_reversal_restores_payable_and_purchase_reversal_waits_for_payments(): void
    {
        $user = $this->salesA();
        $paymentId = $this->actingAs($user)->payDealer('300000')->json('data.payment.id');
        $purchase = CarPurchase::first();

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/purchases/{$purchase->id}/reverse", ['reason' => 'Wrong purchase'])
            ->assertConflict();

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/dealer-payments/{$paymentId}/reverse", ['reason' => 'Paid twice by mistake'])
            ->assertOk()->assertJsonPath('data.dealer.payable', '700000.00');

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/purchases/{$purchase->id}/reverse", ['reason' => 'Wrong purchase'])
            ->assertOk();
    }

    public function test_dealer_settlement_is_independent_of_sale_and_completion(): void
    {
        $user = $this->salesA();
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/sales", [
            'party_id' => CarParty::factory()->create()->id, 'sale_date' => '2026-09-20', 'amount' => '850000',
        ])->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/party-payments", [
            'payment_date' => '2026-09-21', 'amount' => '850000', 'method' => 'cash',
        ])->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/status", ['status' => 'COMPLETED'])->assertOk();

        // The customer has paid in full, but we still owe the dealer: the concepts do not mix.
        $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/sale")->assertJsonPath('data.party.due', '0.00');
        $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/dealer-payments")->assertJsonPath('data.dealer.payable', '700000.00');

        $this->actingAs($user)->payDealer('700000')->assertCreated()->assertJsonPath('data.dealer.payable', '0.00');
        $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/sale")
            ->assertJsonPath('data.party.due', '0.00')
            ->assertJsonPath('data.profit', '150000.00');
        $this->assertSame(CarStatus::Completed, $this->car->fresh()->status);
    }

    public function test_permissions_and_branch_isolation(): void
    {
        $this->getJson("/api/v1/cars/{$this->car->id}/dealer-payments")->assertUnauthorized();

        $viewer = $this->userWith(['car.view', 'car.dealer_payment.view'], [$this->branchA]);
        $this->actingAs($viewer)->getJson("/api/v1/cars/{$this->car->id}/dealer-payments")->assertOk();
        $this->actingAs($viewer)->payDealer('100')->assertForbidden();

        $foreign = $this->purchasedCar('100', CarStatus::InStock, $this->branchB);
        $user = $this->salesA();
        $this->actingAs($user)->getJson("/api/v1/cars/{$foreign->id}/dealer-payments")->assertForbidden();
        $this->actingAs($user)->payDealer('10', $foreign)->assertForbidden();
        $this->assertDatabaseCount('car_dealer_payments', 0);
    }
}
