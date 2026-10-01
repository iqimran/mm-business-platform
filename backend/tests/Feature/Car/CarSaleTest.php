<?php

namespace Tests\Feature\Car;

use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarExpenseType;
use App\Modules\Car\Models\CarParty;
use App\Modules\Car\Models\CarSale;
use App\Modules\Identity\Models\User;

class CarSaleTest extends CarTestCase
{
    private Car $car;

    private CarParty $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->car = $this->purchasedCar('700000');
        $this->party = CarParty::factory()->create();
    }

    private function sell(string $amount = '850000', ?Car $car = null, array $overrides = [])
    {
        $car ??= $this->car;

        return $this->postJson("/api/v1/cars/{$car->id}/sales", array_merge([
            'party_id' => $this->party->id,
            'sale_date' => '2026-09-20',
            'amount' => $amount,
        ], $overrides));
    }

    private function pay(string $amount, ?Car $car = null)
    {
        $car ??= $this->car;

        return $this->postJson("/api/v1/cars/{$car->id}/party-payments", [
            'payment_date' => '2026-09-21',
            'amount' => $amount,
            'method' => 'bank_transfer',
        ]);
    }

    private function saleView(User $user)
    {
        return $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/sale")->assertOk();
    }

    private function addExpense(string $minorAmount): void
    {
        CarExpense::create([
            'car_id' => $this->car->id, 'branch_id' => $this->branchA->id,
            'expense_type_id' => CarExpenseType::factory()->create()->id, 'expense_date' => '2026-09-05',
            'amount_minor' => (int) $minorAmount, 'recorded_by' => User::factory()->create()->id,
        ]);
    }

    public function test_sale_moves_car_to_sold_and_profit_matches_domain_example(): void
    {
        $user = $this->salesA();
        $this->addExpense('3000000');
        $this->addExpense('2500000');

        $this->actingAs($user)->sell('850000')
            ->assertCreated()
            ->assertJsonPath('data.amount', '850000.00')
            ->assertJsonPath('data.party.id', $this->party->id);

        $this->assertSame(CarStatus::Sold, $this->car->fresh()->status);
        $this->assertSame(CarStatus::ReadyForSale, CarSale::first()->status_before_sale);

        $this->saleView($user)
            ->assertJsonPath('data.status', 'SOLD')
            ->assertJsonPath('data.party', ['amount' => '850000.00', 'received' => '0.00', 'due' => '850000.00', 'is_settled' => false])
            ->assertJsonPath('data.profit', '95000.00');

        $this->assertDatabaseHas('audit_logs', ['action' => 'car.sale_recorded', 'branch_id' => $this->branchA->id]);
    }

    public function test_profit_is_hidden_without_cost_permissions(): void
    {
        $this->actingAs($this->salesA())->sell()->assertCreated();
        $salesOnly = $this->userWith(['car.view', 'car.sale.view'], [$this->branchA]);

        $this->saleView($salesOnly)->assertJsonPath('data.profit', null)->assertJsonPath('data.party.due', '850000.00');
    }

    public function test_car_cannot_be_sold_twice(): void
    {
        $user = $this->salesA();
        $this->actingAs($user)->sell()->assertCreated();

        $this->actingAs($user)->sell('900000')->assertConflict()->assertJson(['success' => false]);
        $this->assertSame(1, CarSale::count());
    }

    public function test_sale_requires_purchase_sellable_status_and_valid_input(): void
    {
        $user = $this->salesA();

        $noPurchase = Car::factory()->create(['branch_id' => $this->branchA->id]);
        $noPurchase->forceFill(['status' => CarStatus::ReadyForSale])->save();
        $this->actingAs($user)->sell('1000', $noPurchase)->assertConflict();

        $justBought = $this->purchasedCar('100', CarStatus::Purchased);
        $this->actingAs($user)->sell('1000', $justBought)->assertConflict();

        $inactive = CarParty::factory()->create(['is_active' => false]);
        $this->actingAs($user)->sell('0', null, ['party_id' => $inactive->id, 'sale_date' => now()->addDay()->toDateString()])
            ->assertJsonValidationErrors(['amount', 'party_id', 'sale_date']);

        $this->assertSame(0, CarSale::count());
    }

    public function test_partial_multiple_and_full_party_payments(): void
    {
        $user = $this->salesA();
        $this->actingAs($user)->sell('850000')->assertCreated();

        $this->actingAs($user)->pay('500000')
            ->assertCreated()
            ->assertJsonPath('data.party', ['amount' => '850000.00', 'received' => '500000.00', 'due' => '350000.00', 'is_settled' => false]);
        $this->actingAs($user)->pay('200000.50')->assertJsonPath('data.party.due', '149999.50');
        $this->actingAs($user)->pay('149999.50')->assertJsonPath('data.party.due', '0.00')->assertJsonPath('data.party.is_settled', true);

        $this->saleView($user)->assertJsonCount(3, 'data.payments');
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.party_payment_recorded', 'branch_id' => $this->branchA->id]);
    }

    public function test_overpayment_is_rejected(): void
    {
        $user = $this->salesA();
        $this->actingAs($user)->sell('850000')->assertCreated();
        $this->actingAs($user)->pay('800000')->assertCreated();

        $this->actingAs($user)->pay('50000.01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'The amount exceeds the remaining party due of 50000.00.']);
        $this->saleView($user)->assertJsonPath('data.party.due', '50000.00');
    }

    public function test_payment_requires_an_active_sale_and_valid_input(): void
    {
        $user = $this->salesA();

        $this->actingAs($user)->pay('1000')->assertConflict();

        $this->actingAs($user)->sell()->assertCreated();
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/party-payments", ['amount' => '-5', 'method' => 'barter'])
            ->assertJsonValidationErrors(['amount', 'method', 'payment_date']);
    }

    public function test_reversed_payment_restores_party_due(): void
    {
        $user = $this->salesA();
        $this->actingAs($user)->sell('850000')->assertCreated();
        $id = $this->actingAs($user)->pay('500000')->json('data.payment.id');

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/party-payments/{$id}/reverse", ['reason' => 'Cheque bounced'])
            ->assertOk()
            ->assertJsonPath('data.payment.is_reversed', true)
            ->assertJsonPath('data.party.due', '850000.00');
    }

    public function test_sale_correction_reverses_and_restores_previous_status(): void
    {
        $user = $this->salesA();
        $saleId = $this->actingAs($user)->sell('85000')->json('data.id');
        $paymentId = $this->actingAs($user)->pay('10000')->json('data.payment.id');
        $url = "/api/v1/cars/{$this->car->id}/sales/{$saleId}/reverse";

        // Payments must be reversed before the sale.
        $this->actingAs($user)->postJson($url, ['reason' => 'Wrong sale amount'])->assertConflict();

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/party-payments/{$paymentId}/reverse", ['reason' => 'Undo for correction'])->assertOk();
        $this->actingAs($user)->postJson($url, ['reason' => 'Wrong sale amount'])->assertOk()->assertJsonPath('data.is_reversed', true);
        $this->assertSame(CarStatus::ReadyForSale, $this->car->fresh()->status);

        $this->actingAs($user)->sell('850000')->assertCreated();
        $this->saleView($user)->assertJsonPath('data.party.amount', '850000.00')->assertJsonCount(2, 'data.history');
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.sale_reversed', 'entity_id' => $saleId]);
    }

    public function test_completion_requires_full_payment_and_locks_the_sale(): void
    {
        $user = $this->salesA();
        $saleId = $this->actingAs($user)->sell('850000')->json('data.id');
        $paymentId = $this->actingAs($user)->pay('800000')->json('data.payment.id');
        $status = "/api/v1/cars/{$this->car->id}/status";

        $this->actingAs($user)->postJson($status, ['status' => 'COMPLETED'])->assertConflict();

        $this->actingAs($user)->pay('50000')->assertCreated();
        $this->actingAs($user)->postJson($status, ['status' => 'COMPLETED'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        // Completed: no more party payments, payment reversals or sale reversal.
        $this->actingAs($user)->pay('1')->assertConflict();
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/party-payments/{$paymentId}/reverse", ['reason' => 'Late correction'])->assertConflict();
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/sales/{$saleId}/reverse", ['reason' => 'Late correction'])->assertConflict();

        // Reopening needs a reason, then corrections are possible again.
        $this->actingAs($user)->postJson($status, ['status' => 'SOLD'])->assertJsonValidationErrors('reason');
        $this->actingAs($user)->postJson($status, ['status' => 'SOLD', 'reason' => 'Payment correction needed'])->assertOk();
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/party-payments/{$paymentId}/reverse", ['reason' => 'Late correction'])->assertOk();
    }

    public function test_permissions_and_branch_isolation(): void
    {
        $this->getJson("/api/v1/cars/{$this->car->id}/sale")->assertUnauthorized();

        $viewer = $this->userWith(['car.view', 'car.sale.view', 'car.payment.view'], [$this->branchA]);
        $this->actingAs($viewer)->getJson("/api/v1/cars/{$this->car->id}/sale")->assertOk();
        $this->actingAs($viewer)->sell()->assertForbidden();

        $saleId = $this->actingAs($this->salesA())->sell()->json('data.id');
        $this->actingAs($viewer)->pay('100')->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/cars/{$this->car->id}/sales/{$saleId}/reverse", ['reason' => 'Not allowed'])->assertForbidden();

        $foreign = $this->purchasedCar('100', CarStatus::ReadyForSale, $this->branchB);
        $user = $this->salesA();
        $this->actingAs($user)->getJson("/api/v1/cars/{$foreign->id}/sale")->assertForbidden();
        $this->actingAs($user)->sell('1000', $foreign)->assertForbidden();
        $this->actingAs($user)->pay('100', $foreign)->assertForbidden();
        $this->actingAs($user)->postJson("/api/v1/cars/{$foreign->id}/status", ['status' => 'IN_STOCK'])->assertForbidden();
        $this->assertSame(CarStatus::ReadyForSale, $foreign->fresh()->status);
    }

    public function test_sale_must_belong_to_car_in_url(): void
    {
        $user = $this->salesA();
        $saleId = $this->actingAs($user)->sell()->json('data.id');
        $other = $this->purchasedCar('100');

        $this->actingAs($user)->postJson("/api/v1/cars/{$other->id}/sales/{$saleId}/reverse", ['reason' => 'Wrong car'])->assertNotFound();
    }
}
