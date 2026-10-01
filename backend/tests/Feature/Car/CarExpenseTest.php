<?php

namespace Tests\Feature\Car;

use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarExpenseType;
use App\Modules\Car\Support\FinancialFormulas;
use App\Modules\Shared\Support\Money;

class CarExpenseTest extends CarTestCase
{
    private Car $car;

    private CarExpenseType $paint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->car = Car::factory()->create(['branch_id' => $this->branchA->id]);
        $this->paint = CarExpenseType::factory()->create(['name' => 'Paint']);
    }

    private function expense(string $amount, array $overrides = [], ?Car $car = null)
    {
        $car ??= $this->car;

        return $this->postJson("/api/v1/cars/{$car->id}/expenses", array_merge([
            'expense_type_id' => $this->paint->id,
            'expense_date' => '2026-09-05',
            'amount' => $amount,
            'description' => 'Full body paint',
        ], $overrides));
    }

    public function test_records_multiple_expenses_and_totals_them(): void
    {
        $user = $this->financeA();

        $this->actingAs($user)->expense('30000.00')
            ->assertCreated()
            ->assertJsonPath('data.amount', '30000.00')
            ->assertJsonPath('data.expense_type.name', 'Paint');
        $this->actingAs($user)->expense('25000')->assertCreated();

        $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/expenses")
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.costs.expenses_total', '55000.00');

        $this->assertSame([$this->branchA->id], CarExpense::pluck('branch_id')->unique()->values()->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.expense_recorded', 'branch_id' => $this->branchA->id]);
    }

    public function test_domain_example_investment_and_profit(): void
    {
        $user = $this->financeA();
        $dealer = CarDealer::factory()->create();
        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/purchases", [
            'dealer_id' => $dealer->id, 'purchase_date' => '2026-09-01', 'amount' => '700000',
        ])->assertCreated();
        $this->actingAs($user)->expense('30000')->assertCreated();
        $this->actingAs($user)->expense('25000')->assertCreated();

        $costs = $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/expenses")->json('data.costs');

        $this->assertSame(['purchase_cost' => '700000.00', 'expenses_total' => '55000.00', 'total_investment' => '755000.00'], $costs);
        // Sale is recorded by Task 009; the formula is applied to the recorded costs here.
        $profit = FinancialFormulas::profit(Money::toMinor('850000'), Money::toMinor($costs['purchase_cost']), Money::toMinor($costs['expenses_total']));
        $this->assertSame('95000.00', Money::toDecimal($profit));
    }

    public function test_reversed_expense_is_excluded_from_totals_but_kept(): void
    {
        $user = $this->financeA();
        $wrongId = $this->actingAs($user)->expense('3000')->json('data.id');
        $this->actingAs($user)->expense('25000')->assertCreated();

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/expenses/{$wrongId}/reverse", ['reason' => 'Typed 3000 instead of 30000'])
            ->assertOk()->assertJsonPath('data.is_reversed', true);
        $this->actingAs($user)->expense('30000')->assertCreated();

        $this->actingAs($user)->getJson("/api/v1/cars/{$this->car->id}/expenses")
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.costs.expenses_total', '55000.00');
        $this->assertSame(300000, CarExpense::find($wrongId)->amount_minor);
        $this->assertDatabaseHas('audit_logs', ['action' => 'car.expense_reversed', 'entity_id' => $wrongId]);

        $this->actingAs($user)->postJson("/api/v1/cars/{$this->car->id}/expenses/{$wrongId}/reverse", ['reason' => 'Again please'])
            ->assertConflict();
    }

    public function test_expense_input_is_validated(): void
    {
        $user = $this->financeA();
        $inactive = CarExpenseType::factory()->create(['is_active' => false]);

        $this->actingAs($user)->expense('-100')->assertJsonValidationErrors('amount');
        $this->actingAs($user)->expense('0.001')->assertJsonValidationErrors('amount');
        $this->actingAs($user)->expense('100', [
            'expense_type_id' => $inactive->id,
            'expense_date' => '2026-13-40',
            'description' => str_repeat('x', 300),
        ])->assertJsonValidationErrors(['expense_type_id', 'expense_date', 'description']);

        $this->assertDatabaseCount('car_expenses', 0);
    }

    public function test_completed_car_cannot_receive_expenses(): void
    {
        $this->car->forceFill(['status' => CarStatus::Completed])->save();

        $this->actingAs($this->financeA())->expense('1000')->assertConflict();
    }

    public function test_sold_car_can_still_receive_expenses(): void
    {
        $this->car->forceFill(['status' => CarStatus::Sold])->save();

        $this->actingAs($this->financeA())->expense('1000')->assertCreated();
    }

    public function test_permissions_and_branch_isolation(): void
    {
        $this->getJson("/api/v1/cars/{$this->car->id}/expenses")->assertUnauthorized();

        $viewer = $this->userWith(['car.view', 'car.expense.view'], [$this->branchA]);
        $this->actingAs($viewer)->getJson("/api/v1/cars/{$this->car->id}/expenses")->assertOk();
        $this->actingAs($viewer)->expense('1000')->assertForbidden();

        $foreign = Car::factory()->create(['branch_id' => $this->branchB->id]);
        $user = $this->financeA();
        $this->actingAs($user)->getJson("/api/v1/cars/{$foreign->id}/expenses")->assertForbidden();
        $this->actingAs($user)->expense('1000', [], $foreign)->assertForbidden();

        $this->assertDatabaseCount('car_expenses', 0);
    }
}
