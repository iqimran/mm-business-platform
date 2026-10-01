<?php

namespace Tests\Feature\Car;

use App\Modules\Car\Enums\CarStatus;
use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarFinancialPosition;
use App\Modules\Car\Services\CarFinancials;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Car\Concerns\BuildsCarRecords;

class CarReportTest extends CarTestCase
{
    use BuildsCarRecords;

    private const REPORTER = [
        'car.report.view', 'car.view', 'car.purchase.view', 'car.expense.view',
        'car.sale.view', 'car.payment.view', 'car.dealer_payment.view',
    ];

    private function reporter(array $branches = []): User
    {
        return $this->userWith(self::REPORTER, $branches ?: [$this->branchA]);
    }

    /**
     * Branch A: an unsold car, a sold+partly paid car, a sold+settled car at a loss.
     * Branch B: one sold car (must never leak into branch A reports).
     *
     * @return array<string, Car>
     */
    private function portfolio(): array
    {
        $stock = $this->car(null, CarStatus::ReadyForSale);
        $stock->update(['brand' => 'Nissan']);
        $p = $this->purchase($stock, '400000');
        $this->expense($stock, '12000');
        $this->dealerPayment($p, '100000');

        $partly = $this->car();
        $partly->update(['brand' => 'Toyota']);
        $p2 = $this->purchase($partly, '700000');
        $this->expense($partly, '30000');
        $this->expense($partly, '25000');
        $this->reverse($this->expense($partly, '9999'));
        $s2 = $this->sale($partly, '850000', '2026-09-10');
        $this->partyPayment($s2, '500000');
        $this->dealerPayment($p2, '700000');

        $loss = $this->car();
        $loss->update(['brand' => 'Honda']);
        $this->purchase($loss, '300000.50');
        $s3 = $this->sale($loss, '250000', '2026-10-02');
        $this->partyPayment($s3, '250000');

        $foreign = $this->car($this->branchB);
        $foreign->update(['brand' => 'Mazda']);
        $this->purchase($foreign, '100000');
        $this->sale($foreign, '999999', '2026-09-15');

        return compact('stock', 'partly', 'loss', 'foreign');
    }

    public function test_view_matches_domain_service_for_every_car(): void
    {
        $cars = $this->portfolio();
        $service = app(CarFinancials::class);

        foreach ($cars as $car) {
            $row = CarFinancialPosition::find($car->id);
            $s = $service->snapshot($car);

            $this->assertSame(
                [$s->purchaseCost, $s->expensesTotal, $s->totalInvestment, $s->salePrice, $s->partyReceived, $s->partyDue, $s->dealerPurchaseAmount, $s->dealerPaid, $s->dealerPayable, $s->profit],
                [$row->purchase_cost, $row->expenses_total, $row->total_investment, $row->sale_amount, $row->party_received, $row->party_due, $row->dealer_purchase_amount, $row->dealer_paid, $row->dealer_payable, $row->profit],
                "View and CarFinancials disagree for {$car->brand}",
            );
        }
    }

    public function test_car_register_rows_totals_and_state_filters(): void
    {
        $this->portfolio();
        $user = $this->reporter();

        $all = $this->actingAs($user)->getJson('/api/v1/car-reports/cars?sort=brand&direction=asc')->assertOk()->json('data');
        $this->assertSame(['Honda', 'Nissan', 'Toyota'], array_column(array_column($all['items'], 'car'), 'brand'));
        $this->assertSame([
            'cars' => 3, 'sold' => 2,
            'purchase_cost' => '1400000.50', 'expenses_total' => '67000.00', 'total_investment' => '1467000.50',
            'sale_amount' => '1100000.00', 'party_received' => '750000.00', 'party_due' => '350000.00',
            'dealer_paid' => '800000.00', 'dealer_payable' => '600000.50', 'profit' => '44999.50',
        ], $all['totals']);

        $toyota = collect($all['items'])->firstWhere('car.brand', 'Toyota');
        $this->assertSame(['755000.00', '95000.00', '350000.00', '0.00'], [$toyota['total_investment'], $toyota['profit'], $toyota['party_due'], $toyota['dealer_payable']]);

        $stock = $this->actingAs($user)->getJson('/api/v1/car-reports/cars?state=unsold')->json('data');
        $this->assertSame(1, $stock['totals']['cars']);
        $this->assertSame('412000.00', $stock['totals']['total_investment']);
        $this->assertGreaterThanOrEqual(0, $stock['items'][0]['days_in_stock']);
        $this->assertNull($stock['items'][0]['profit']);

        $this->actingAs($user)->getJson('/api/v1/car-reports/cars?state=sold')->assertJsonPath('data.totals.cars', 2);
        $this->actingAs($user)->getJson('/api/v1/car-reports/cars?status=READY_FOR_SALE')->assertJsonPath('data.totals.cars', 1);
        $this->actingAs($user)->getJson('/api/v1/car-reports/cars?search=toy')->assertJsonPath('data.totals.cars', 1);
    }

    public function test_pagination_and_totals_cover_the_full_filtered_set(): void
    {
        $this->portfolio();

        $page2 = $this->actingAs($this->reporter())->getJson('/api/v1/car-reports/cars?per_page=1&page=2&sort=brand&direction=asc')
            ->assertOk()->json('data');

        $this->assertCount(1, $page2['items']);
        $this->assertSame('Nissan', $page2['items'][0]['car']['brand']);
        $this->assertSame(['current_page' => 2, 'per_page' => 1, 'total' => 3, 'last_page' => 3], $page2['pagination']);
        $this->assertSame(3, $page2['totals']['cars']);
        $this->assertSame('44999.50', $page2['totals']['profit']);
    }

    public function test_sorting_by_financial_columns_with_nulls_last(): void
    {
        $this->portfolio();
        $user = $this->reporter();

        $byInvestment = $this->actingAs($user)->getJson('/api/v1/car-reports/cars?sort=total_investment&direction=desc')->json('data.items');
        $this->assertSame(['Toyota', 'Nissan', 'Honda'], array_column(array_column($byInvestment, 'car'), 'brand'));

        // Unsold car has no profit and sorts last in both directions.
        $byProfitAsc = $this->actingAs($user)->getJson('/api/v1/car-reports/cars?sort=profit&direction=asc')->json('data.items');
        $this->assertSame(['Honda', 'Toyota', 'Nissan'], array_column(array_column($byProfitAsc, 'car'), 'brand'));

        $this->actingAs($user)->getJson('/api/v1/car-reports/cars?sort=chassis_number;drop')->assertJsonValidationErrors('sort');
        $this->actingAs($user)->getJson('/api/v1/car-reports/cars?direction=sideways')->assertJsonValidationErrors('direction');
    }

    public function test_sales_report_period_settlement_and_profit_totals(): void
    {
        $this->portfolio();
        $user = $this->reporter();

        $sept = $this->actingAs($user)->getJson('/api/v1/car-reports/sales?from=2026-09-01&to=2026-09-30')->json('data');
        $this->assertSame(1, $sept['totals']['cars']);
        $this->assertSame('95000.00', $sept['totals']['profit']);

        $all = $this->actingAs($user)->getJson('/api/v1/car-reports/sales?sort=profit&direction=desc')->json('data');
        $this->assertSame(['Toyota', 'Honda'], array_column(array_column($all['items'], 'car'), 'brand'));
        $this->assertSame('-50000.50', $all['items'][1]['profit']);

        $this->actingAs($user)->getJson('/api/v1/car-reports/sales?settled=yes')->assertJsonPath('data.totals.cars', 1)->assertJsonPath('data.items.0.car.brand', 'Honda');
        $this->actingAs($user)->getJson('/api/v1/car-reports/sales?settled=no')->assertJsonPath('data.totals.party_due', '350000.00');
        $this->actingAs($user)->getJson('/api/v1/car-reports/sales?from=2026-10-01&to=2026-09-01')->assertJsonValidationErrors('to');
    }

    public function test_receivables_and_payables_are_separate_and_groupable(): void
    {
        $this->portfolio();
        $user = $this->reporter();

        $receivables = $this->actingAs($user)->getJson('/api/v1/car-reports/receivables')->json('data');
        $this->assertSame(1, $receivables['totals']['cars']);
        $this->assertSame('350000.00', $receivables['items'][0]['party_due']);

        $byParty = $this->actingAs($user)->getJson('/api/v1/car-reports/receivables?group=party')->json('data');
        $this->assertSame('party', $byParty['group']);
        $this->assertSame(['cars' => 1, 'original' => '850000.00', 'settled' => '500000.00', 'outstanding' => '350000.00'],
            array_intersect_key($byParty['items'][0], array_flip(['cars', 'original', 'settled', 'outstanding'])));

        $payables = $this->actingAs($user)->getJson('/api/v1/car-reports/payables?sort=amount&direction=desc')->json('data');
        $this->assertSame(['Honda', 'Nissan'], array_column(array_column($payables['items'], 'car'), 'brand'));
        $this->assertSame('600000.50', $payables['totals']['dealer_payable']);

        $this->actingAs($user)->getJson('/api/v1/car-reports/payables?group=dealer')->assertJsonCount(2, 'data.items');
        $this->actingAs($user)->getJson('/api/v1/car-reports/receivables?older_than_days=100000')->assertJsonValidationErrors('older_than_days');
    }

    public function test_expense_report_excludes_reversals_and_breaks_down_by_type(): void
    {
        $this->portfolio();

        $data = $this->actingAs($this->reporter())->getJson('/api/v1/car-reports/expenses?per_page=2&sort=amount&direction=desc')->json('data');

        $this->assertSame(3, $data['totals']['entries']);
        $this->assertSame('67000.00', $data['totals']['total']);
        $this->assertCount(3, $data['totals']['by_type']);
        $this->assertSame(['30000.00', '25000.00'], array_column($data['items'], 'amount'));
        $this->assertSame(2, $data['pagination']['last_page']);
    }

    public function test_branch_report_compares_accessible_branches(): void
    {
        $this->portfolio();

        $local = $this->actingAs($this->reporter())->getJson('/api/v1/car-reports/branches')->json('data');
        $this->assertSame(['A'], array_column(array_column($local['items'], 'branch'), 'code'));
        $this->assertSame([
            'stock_cars' => 1, 'sold_cars' => 2, 'stock_investment' => '412000.00', 'expenses' => '67000.00',
            'sales_total' => '1100000.00', 'profit' => '44999.50', 'party_due' => '350000.00', 'dealer_payable' => '600000.50',
        ], $local['totals']);

        $global = $this->userWith([...self::REPORTER, 'branch.access_all']);
        $all = $this->actingAs($global)->getJson('/api/v1/car-reports/branches?from=2026-09-01&to=2026-09-30')->json('data');
        $this->assertSame(['A', 'B'], array_column(array_column($all['items'], 'branch'), 'code'));
        // September sales: branch A 850000 (Toyota) + branch B 999999 (Mazda); Honda was sold in October.
        $this->assertSame('1849999.00', $all['totals']['sales_total']);
        $this->assertSame(['850000.00', '999999.00'], array_column($all['items'], 'sales_total'));
    }

    public function test_branch_isolation_in_every_report(): void
    {
        $this->portfolio();
        $local = $this->reporter();

        foreach (['cars', 'sales', 'receivables', 'payables', 'expenses'] as $report) {
            $json = json_encode($this->actingAs($local)->getJson("/api/v1/car-reports/{$report}")->assertOk()->json('data'));
            $this->assertStringNotContainsString('Mazda', $json, "{$report} leaked another branch");
        }

        $this->actingAs($local)->getJson("/api/v1/car-reports/cars?branch_id={$this->branchB->id}")->assertJsonPath('data.totals.cars', 0);
        $this->actingAs($local)->getJson("/api/v1/car-reports/branches?branch_id={$this->branchB->id}")->assertJsonCount(0, 'data.items');
    }

    public function test_reports_require_permissions(): void
    {
        $this->getJson('/api/v1/car-reports/cars')->assertUnauthorized();

        $noReport = $this->userWith(array_diff(self::REPORTER, ['car.report.view']), [$this->branchA]);
        $this->actingAs($noReport)->getJson('/api/v1/car-reports/cars')->assertForbidden();

        $carsOnly = $this->userWith(['car.report.view', 'car.view'], [$this->branchA]);
        $this->actingAs($carsOnly)->getJson('/api/v1/car-reports/sales')->assertForbidden();
        $this->actingAs($carsOnly)->getJson('/api/v1/car-reports/payables')->assertForbidden();
        $this->actingAs($carsOnly)->getJson('/api/v1/car-reports/expenses')->assertForbidden();
    }

    public function test_hidden_figures_are_null_and_not_sortable(): void
    {
        $this->portfolio();
        $carsOnly = $this->userWith(['car.report.view', 'car.view'], [$this->branchA]);

        $data = $this->actingAs($carsOnly)->getJson('/api/v1/car-reports/cars')->assertOk()->json('data');
        foreach (['purchase_cost', 'total_investment', 'sale_amount', 'party_due', 'dealer_payable', 'profit'] as $field) {
            $this->assertNull($data['totals'][$field], $field);
            $this->assertNull($data['items'][0][$field], $field);
        }
        $this->assertSame(3, $data['totals']['cars']);

        $this->actingAs($carsOnly)->getJson('/api/v1/car-reports/cars?sort=profit')->assertJsonValidationErrors('sort');

        $salesOnly = $this->userWith(['car.report.view', 'car.sale.view'], [$this->branchA]);
        $this->actingAs($salesOnly)->getJson('/api/v1/car-reports/sales')->assertJsonPath('data.totals.profit', null);
        $this->actingAs($salesOnly)->getJson('/api/v1/car-reports/sales?sort=profit')->assertJsonValidationErrors('sort');
    }

    public function test_query_count_does_not_grow_with_rows(): void
    {
        $user = $this->reporter();
        $count = function () use ($user) {
            // Warm up: the first request also loads the user's permissions and branches (memoized afterwards).
            $this->actingAs($user)->getJson('/api/v1/car-reports/cars?per_page=50')->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->getJson('/api/v1/car-reports/cars?per_page=50')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        foreach (range(1, 3) as $i) {
            $this->sale($this->carWithPurchase(), '1000');
        }
        $few = $count();

        foreach (range(1, 12) as $i) {
            $this->sale($this->carWithPurchase(), '1000');
        }

        $this->assertSame($few, $count(), 'Report queries grew with the number of rows (N+1).');
    }

    private function carWithPurchase(): Car
    {
        $car = $this->car();
        $this->purchase($car, (string) random_int(100, 999));

        return $car;
    }
}
