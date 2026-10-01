<?php

namespace Tests\Feature\Car;

use App\Modules\Branch\Models\Branch;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Services\CarFinancials;
use App\Modules\Shared\Support\Money;
use Tests\Feature\Car\Concerns\BuildsCarRecords;

class CarFinancialIntelligenceTest extends CarTestCase
{
    use BuildsCarRecords;

    private const ALL_FINANCE = [
        'car.view', 'car.purchase.view', 'car.expense.view', 'car.sale.view',
        'car.payment.view', 'car.dealer_payment.view',
    ];

    private function snapshot(Car $car)
    {
        return app(CarFinancials::class)->snapshot($car);
    }

    // ---- Per-car calculations ----

    public function test_car_without_records_has_zero_costs_and_no_positions(): void
    {
        $s = $this->snapshot($this->car());

        $this->assertSame([0, 0, 0], [$s->purchaseCost, $s->expensesTotal, $s->totalInvestment]);
        $this->assertNull($s->salePrice);
        $this->assertNull($s->partyDue);
        $this->assertNull($s->dealerPayable);
        $this->assertNull($s->profit);
    }

    public function test_purchase_only_sets_investment_and_dealer_payable_but_no_profit(): void
    {
        $car = $this->car();
        $this->purchase($car, '700000');

        $s = $this->snapshot($car);

        $this->assertSame(Money::toMinor('700000'), $s->totalInvestment);
        $this->assertSame(Money::toMinor('700000'), $s->dealerPayable);
        $this->assertNull($s->profit);
        $this->assertNull($s->partyDue);
    }

    public function test_domain_example_every_figure(): void
    {
        $car = $this->car();
        $purchase = $this->purchase($car, '700000');
        $this->expense($car, '30000');
        $this->expense($car, '25000');
        $sale = $this->sale($car, '850000');
        $this->partyPayment($sale, '500000');
        $this->dealerPayment($purchase, '200000');

        $s = $this->snapshot($car);

        $this->assertSame(Money::toMinor('700000'), $s->purchaseCost);
        $this->assertSame(Money::toMinor('55000'), $s->expensesTotal);
        $this->assertSame(Money::toMinor('755000'), $s->totalInvestment);
        $this->assertSame(Money::toMinor('850000'), $s->salePrice);
        $this->assertSame(Money::toMinor('95000'), $s->profit);
        $this->assertSame(Money::toMinor('350000'), $s->partyDue);
        $this->assertSame(Money::toMinor('500000'), $s->dealerPayable);
        $this->assertFalse($s->isPartySettled());
        $this->assertFalse($s->isDealerSettled());
    }

    public function test_loss_and_break_even(): void
    {
        $loss = $this->car();
        $this->purchase($loss, '700000');
        $this->expense($loss, '55000');
        $this->sale($loss, '750000');
        $this->assertSame(-Money::toMinor('5000'), $this->snapshot($loss)->profit);

        $even = $this->car();
        $this->purchase($even, '500000');
        $this->sale($even, '500000');
        $this->assertSame(0, $this->snapshot($even)->profit);
    }

    public function test_reversed_records_are_excluded_everywhere(): void
    {
        $car = $this->car();
        $wrongPurchase = $this->purchase($car, '999999');
        $this->reverse($wrongPurchase);
        $purchase = $this->purchase($car, '700000');
        $this->reverse($this->expense($car, '3000'));
        $this->expense($car, '30000');
        $this->reverse($this->dealerPayment($purchase, '100000'));
        $wrongSale = $this->sale($car, '1');
        $this->reverse($wrongSale);
        $sale = $this->sale($car, '850000');
        $this->reverse($this->partyPayment($sale, '850000'));
        $this->partyPayment($sale, '100000');

        $s = $this->snapshot($car);

        $this->assertSame(Money::toMinor('700000'), $s->purchaseCost);
        $this->assertSame(Money::toMinor('30000'), $s->expensesTotal);
        $this->assertSame(Money::toMinor('850000'), $s->salePrice);
        $this->assertSame(Money::toMinor('750000'), $s->partyDue);
        $this->assertSame(Money::toMinor('700000'), $s->dealerPayable);
        $this->assertSame(Money::toMinor('120000'), $s->profit);
    }

    public function test_paisa_precision_is_exact(): void
    {
        $car = $this->car();
        $this->purchase($car, '0.10');
        $this->expense($car, '0.20');
        $sale = $this->sale($car, '0.31');
        $this->partyPayment($sale, '0.30');

        $s = $this->snapshot($car);

        // 0.10 + 0.20 is exactly 0.30 (no float drift), so profit is exactly 0.01.
        $this->assertSame(30, $s->totalInvestment);
        $this->assertSame(1, $s->profit);
        $this->assertSame(1, $s->partyDue);
    }

    public function test_very_large_amounts_do_not_overflow(): void
    {
        $car = $this->car();
        $max = Money::toDecimal(Money::MAX_MINOR);
        $this->purchase($car, $max);
        $this->expense($car, $max);
        $this->expense($car, $max);

        $s = $this->snapshot($car);

        $this->assertSame(3 * Money::MAX_MINOR, $s->totalInvestment);
        $this->assertSame('2999999999999.97', Money::toDecimal($s->totalInvestment));
    }

    public function test_settled_flags_when_fully_paid(): void
    {
        $car = $this->car();
        $purchase = $this->purchase($car, '700000');
        $sale = $this->sale($car, '850000');
        $this->partyPayment($sale, '850000');
        $this->dealerPayment($purchase, '700000');

        $s = $this->snapshot($car);

        $this->assertSame(0, $s->partyDue);
        $this->assertSame(0, $s->dealerPayable);
        $this->assertTrue($s->isPartySettled());
        $this->assertTrue($s->isDealerSettled());
    }

    public function test_concepts_do_not_influence_each_other(): void
    {
        $car = $this->car();
        $purchase = $this->purchase($car, '700000');
        $sale = $this->sale($car, '850000');
        $before = $this->snapshot($car);

        $this->expense($car, '10000');           // affects investment and profit only
        $this->dealerPayment($purchase, '300000'); // affects dealer payable only
        $this->partyPayment($sale, '400000');      // affects party due only
        $after = $this->snapshot($car);

        $this->assertSame($before->profit - Money::toMinor('10000'), $after->profit);
        $this->assertSame($before->dealerPayable - Money::toMinor('300000'), $after->dealerPayable);
        $this->assertSame($before->partyDue - Money::toMinor('400000'), $after->partyDue);
        $this->assertSame($before->salePrice, $after->salePrice);
        $this->assertSame($before->purchaseCost, $after->purchaseCost);
    }

    // ---- Summary endpoint ----

    public function test_summary_endpoint_returns_backend_figures(): void
    {
        $car = $this->car();
        $purchase = $this->purchase($car, '700000');
        $this->expense($car, '55000');
        $sale = $this->sale($car, '850000');
        $this->partyPayment($sale, '850000');
        $this->dealerPayment($purchase, '200000');

        $this->actingAs($this->userWith(self::ALL_FINANCE, [$this->branchA]))
            ->getJson("/api/v1/cars/{$car->id}/financial-summary")
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Operation completed successfully.', 'data' => [
                'car_id' => $car->id,
                'status' => 'SOLD',
                'costs' => ['purchase_cost' => '700000.00', 'expenses_total' => '55000.00', 'total_investment' => '755000.00'],
                'party' => ['amount' => '850000.00', 'received' => '850000.00', 'due' => '0.00', 'is_settled' => true],
                'dealer' => ['purchase_amount' => '700000.00', 'paid' => '200000.00', 'payable' => '500000.00', 'is_settled' => false],
                'profit' => '95000.00',
            ]]);
    }

    public function test_summary_hides_figures_the_user_may_not_see(): void
    {
        $car = $this->car();
        $this->purchase($car, '700000');
        $this->expense($car, '55000');
        $this->sale($car, '850000');
        $url = "/api/v1/cars/{$car->id}/financial-summary";

        $this->actingAs($this->userWith(['car.view'], [$this->branchA]))->getJson($url)
            ->assertJsonPath('data.costs', null)->assertJsonPath('data.party', null)
            ->assertJsonPath('data.dealer', null)->assertJsonPath('data.profit', null);

        $this->actingAs($this->userWith(['car.view', 'car.purchase.view', 'car.sale.view'], [$this->branchA]))->getJson($url)
            ->assertJsonPath('data.costs', ['purchase_cost' => '700000.00', 'expenses_total' => null, 'total_investment' => null])
            ->assertJsonPath('data.party.due', '850000.00')
            ->assertJsonPath('data.profit', null);
    }

    public function test_summary_and_timeline_respect_branch_and_authentication(): void
    {
        $foreign = $this->car($this->branchB);
        $user = $this->userWith(self::ALL_FINANCE, [$this->branchA]);

        $this->getJson("/api/v1/cars/{$foreign->id}/financial-summary")->assertUnauthorized();
        $this->actingAs($user)->getJson("/api/v1/cars/{$foreign->id}/financial-summary")->assertForbidden();
        $this->actingAs($user)->getJson("/api/v1/cars/{$foreign->id}/timeline")->assertForbidden();
    }

    // ---- Timeline ----

    public function test_timeline_lists_events_in_order_with_reversals(): void
    {
        $car = $this->car();
        $purchase = $this->purchase($car, '700000');
        $wrong = $this->expense($car, '3000');
        $this->reverse($wrong);
        $sale = $this->sale($car, '850000');
        $this->partyPayment($sale, '100000');
        $this->dealerPayment($purchase, '50000');

        $events = collect($this->actingAs($this->userWith(self::ALL_FINANCE, [$this->branchA]))
            ->getJson("/api/v1/cars/{$car->id}/timeline")->assertOk()->json('data'));

        $this->assertSame(['purchase', 'dealer_payment', 'expense', 'sale', 'party_payment', 'expense_reversed'], $events->pluck('type')->all());
        $this->assertSame('-3000.00', $events->firstWhere('type', 'expense_reversed')['amount']);
        $this->assertTrue($events->firstWhere('type', 'expense_reversed')['is_reversal']);

        $limited = collect($this->actingAs($this->userWith(['car.view', 'car.sale.view'], [$this->branchA]))
            ->getJson("/api/v1/cars/{$car->id}/timeline")->json('data'));
        $this->assertSame(['sale'], $limited->pluck('type')->unique()->values()->all());
    }

    // ---- Dashboard ----

    public function test_dashboard_aggregates_equal_sum_of_per_car_figures(): void
    {
        $cars = [];
        // Unsold car with costs.
        $cars[] = $c1 = $this->car();
        $p1 = $this->purchase($c1, '400000');
        $this->expense($c1, '12345.67');
        $this->dealerPayment($p1, '100000');
        // Sold, partly paid, with a reversed payment.
        $cars[] = $c2 = $this->car();
        $p2 = $this->purchase($c2, '700000');
        $this->expense($c2, '55000');
        $s2 = $this->sale($c2, '850000');
        $this->partyPayment($s2, '500000');
        $this->reverse($this->partyPayment($s2, '100000'));
        $this->dealerPayment($p2, '700000');
        // Sold at a loss, fully paid.
        $cars[] = $c3 = $this->car();
        $this->purchase($c3, '300000.50');
        $s3 = $this->sale($c3, '250000');
        $this->partyPayment($s3, '250000');
        // A car of another branch must not be counted.
        $other = $this->car($this->branchB);
        $this->purchase($other, '999999');

        $sum = fn (callable $f) => array_sum(array_map(fn (Car $c) => $f($this->snapshot($c)) ?? 0, $cars));
        $unsold = [$c1];

        $data = $this->actingAs($this->userWith(self::ALL_FINANCE, [$this->branchA]))
            ->getJson('/api/v1/car-dashboard')->assertOk()->json('data');

        $this->assertSame(Money::toDecimal(array_sum(array_map(fn ($c) => $this->snapshot($c)->totalInvestment, $unsold))), $data['stock']['total_investment']);
        $this->assertSame(Money::toDecimal($sum(fn ($s) => $s->partyDue)), $data['receivables']['party_due']);
        $this->assertSame(Money::toDecimal($sum(fn ($s) => $s->dealerPayable)), $data['payables']['dealer_payable']);
        $this->assertSame(Money::toDecimal($sum(fn ($s) => $s->profit)), $data['sales']['profit']['profit']);
        $this->assertSame(2, $data['sales']['count']);

        // Expected literal values: 350000 due; 300000 + 300000.50 payable; 95000 - 50000.50 profit.
        $this->assertSame('350000.00', $data['receivables']['party_due']);
        $this->assertSame('600000.50', $data['payables']['dealer_payable']);
        $this->assertSame('44999.50', $data['sales']['profit']['profit']);
        $this->assertSame(['PURCHASED' => 0, 'IN_STOCK' => 1, 'PREPARATION' => 0, 'READY_FOR_SALE' => 0, 'SOLD' => 2, 'COMPLETED' => 0], $data['cars_by_status']);
    }

    public function test_dashboard_filters_by_period_and_branch(): void
    {
        $september = $this->car();
        $this->purchase($september, '100');
        $this->sale($september, '200', '2026-09-10');
        $october = $this->car();
        $this->purchase($october, '100');
        $this->sale($october, '300', '2026-10-05');
        $foreign = $this->car($this->branchB);
        $this->purchase($foreign, '100');
        $this->sale($foreign, '5000', '2026-09-15');

        $global = $this->userWith([...self::ALL_FINANCE, 'branch.access_all']);

        $this->actingAs($global)->getJson('/api/v1/car-dashboard?from=2026-09-01&to=2026-09-30')
            ->assertJsonPath('data.sales.count', 2)->assertJsonPath('data.sales.sale_total', '5200.00');
        $this->actingAs($global)->getJson("/api/v1/car-dashboard?branch_id={$this->branchA->id}&from=2026-09-01&to=2026-09-30")
            ->assertJsonPath('data.sales.count', 1)->assertJsonPath('data.sales.profit.profit', '100.00');

        $local = $this->userWith(self::ALL_FINANCE, [$this->branchA]);
        $this->actingAs($local)->getJson("/api/v1/car-dashboard?branch_id={$this->branchB->id}")
            ->assertJsonPath('data.sales.count', 0)->assertJsonPath('data.receivables.sale_amount', '0.00');

        $this->actingAs($local)->getJson('/api/v1/car-dashboard?from=2026-10-01&to=2026-09-01')->assertJsonValidationErrors('to');
    }

    public function test_dashboard_requires_permission_and_hides_restricted_blocks(): void
    {
        $this->getJson('/api/v1/car-dashboard')->assertUnauthorized();
        $this->actingAs($this->userWith([], [$this->branchA]))->getJson('/api/v1/car-dashboard')->assertForbidden();

        $this->actingAs($this->userWith(['car.view', 'car.sale.view'], [$this->branchA]))->getJson('/api/v1/car-dashboard')
            ->assertOk()
            ->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.payables', null)
            ->assertJsonPath('data.sales.profit', null)
            ->assertJsonPath('data.receivables.party_due', '0.00');
    }
}
