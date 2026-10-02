<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\ExpenseCategory;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\Hall;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use Illuminate\Support\Facades\DB;

/**
 * Restaurant reports over data created through the real API:
 *
 * Branch A sales:  today 200 (paid 200, walk-in) · today 250 (paid 50) · yesterday 250 (unpaid) · today 100 (reversed)
 * Branch B sales:  today 100 (paid 100)
 * Branch A halls:  today 1000 (paid 400) · in 3 days 2000 (unpaid) · in 3 days 500 (cancelled)
 * Branch A costs:  today Food 300, Utilities 200 · yesterday Food 100 · today Food 50 (reversed)
 * Branch B costs:  today Food 999
 */
class RestaurantReportTest extends RestaurantTestCase
{
    private const REPORTS = '/api/v1/restaurant/reports';

    private const OPERATIONS = [
        'restaurant.sale.view', 'restaurant.sale.create', 'restaurant.sale.reverse',
        'restaurant.booking.view', 'restaurant.booking.create', 'restaurant.booking.cancel',
        'restaurant.expense.view', 'restaurant.expense.create', 'restaurant.expense.reverse',
    ];

    private User $manager;

    private string $today;

    private string $yesterday;

    private string $inThreeDays;

    private ExpenseCategory $food;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = now()->toDateString();
        $this->yesterday = now()->subDay()->toDateString();
        $this->inThreeDays = now()->addDays(3)->toDateString();
        $this->manager = $this->userWith([...self::OPERATIONS, 'restaurant.report.view'], [$this->branchA]);
        $managerB = $this->userWith(self::OPERATIONS, [$this->branchB]);

        $customer = RestaurantCustomer::factory()->create(['name' => 'Karim']);
        $tea = MenuItem::factory()->create(['price_minor' => 10000]);
        $biryani = MenuItem::factory()->create(['price_minor' => 25000]);

        $sell = fn (User $as, string $branch, array $data) => $this->actingAs($as)->postJson('/api/v1/restaurant/sales', $data + [
            'branch_id' => $branch, 'customer_id' => $customer->id,
        ])->assertCreated()->json('data.id');

        $sell($this->manager, $this->branchA->id, ['customer_id' => null, 'items' => [['menu_item_id' => $tea->id, 'quantity' => 2]], 'payment' => ['amount' => '200', 'method' => 'cash']]);
        $sell($this->manager, $this->branchA->id, ['items' => [['menu_item_id' => $biryani->id, 'quantity' => 1]], 'payment' => ['amount' => '50', 'method' => 'cash']]);
        $sell($this->manager, $this->branchA->id, ['items' => [['menu_item_id' => $biryani->id, 'quantity' => 1]], 'sold_at' => "{$this->yesterday} 12:00"]);
        $reversed = $sell($this->manager, $this->branchA->id, ['items' => [['menu_item_id' => $tea->id, 'quantity' => 1]]]);
        $this->actingAs($this->manager)->postJson("/api/v1/restaurant/sales/{$reversed}/reverse", ['reason' => 'Mistake'])->assertOk();
        $sell($managerB, $this->branchB->id, ['customer_id' => null, 'items' => [['menu_item_id' => $tea->id, 'quantity' => 1]], 'payment' => ['amount' => '100', 'method' => 'cash']]);

        $hall = Hall::factory()->create(['branch_id' => $this->branchA->id, 'name' => 'Grand']);
        $book = fn (array $data) => $this->actingAs($this->manager)->postJson('/api/v1/restaurant/hall-bookings', $data + [
            'hall_id' => $hall->id, 'customer_id' => $customer->id,
        ])->assertCreated()->json('data.id');
        $book(['booking_date' => $this->today, 'start_time' => '18:00', 'end_time' => '22:00', 'agreed_amount' => '1000', 'payment' => ['amount' => '400', 'method' => 'cash']]);
        $book(['booking_date' => $this->inThreeDays, 'start_time' => '10:00', 'end_time' => '12:00', 'agreed_amount' => '2000']);
        $cancelled = $book(['booking_date' => $this->inThreeDays, 'start_time' => '13:00', 'end_time' => '14:00', 'agreed_amount' => '500']);
        $this->actingAs($this->manager)->postJson("/api/v1/restaurant/hall-bookings/{$cancelled}/cancel", ['reason' => 'Not needed'])->assertOk();

        $this->food = ExpenseCategory::factory()->create(['name' => 'Food Purchase']);
        $utilities = ExpenseCategory::factory()->create(['name' => 'Utilities']);
        $spend = fn (User $as, string $branch, ExpenseCategory $category, string $date, string $amount) => $this->actingAs($as)->postJson('/api/v1/restaurant/expenses', [
            'branch_id' => $branch, 'category_id' => $category->id, 'expense_date' => $date, 'amount' => $amount,
        ])->assertCreated()->json('data.id');
        $spend($this->manager, $this->branchA->id, $this->food, $this->today, '300');
        $spend($this->manager, $this->branchA->id, $utilities, $this->today, '200');
        $spend($this->manager, $this->branchA->id, $this->food, $this->yesterday, '100');
        $wrong = $spend($this->manager, $this->branchA->id, $this->food, $this->today, '50');
        $this->actingAs($this->manager)->postJson("/api/v1/restaurant/expenses/{$wrong}/reverse", ['reason' => 'Duplicate'])->assertOk();
        $spend($managerB, $this->branchB->id, $this->food, $this->today, '999');
    }

    private function report(string $path, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->getJson(self::REPORTS.$path);
    }

    // ---- Food sales ----------------------------------------------------------------------------

    public function test_sales_totals_and_date_filtering(): void
    {
        $this->report('/sales')->assertOk()
            ->assertJsonPath('data.totals', ['count' => 3, 'total' => '700.00', 'paid' => '250.00', 'due' => '450.00'])
            ->assertJsonPath('data.pagination.total', 3);

        $this->report("/sales?date={$this->today}")
            ->assertJsonPath('data.totals', ['count' => 2, 'total' => '450.00', 'paid' => '250.00', 'due' => '200.00']);
        $this->report("/sales?date_from={$this->yesterday}&date_to={$this->yesterday}")
            ->assertJsonPath('data.totals.total', '250.00')->assertJsonPath('data.items.0.payment_status', 'unpaid');
        $this->report('/sales?payment_status=partial')->assertJsonPath('data.totals.count', 1)->assertJsonPath('data.items.0.due', '200.00');
    }

    public function test_daily_sales(): void
    {
        $this->report('/sales?group_by=day')->assertOk()
            ->assertJsonPath('data.items', [
                ['date' => $this->today, 'count' => 2, 'total' => '450.00', 'paid' => '250.00', 'due' => '200.00'],
                ['date' => $this->yesterday, 'count' => 1, 'total' => '250.00', 'paid' => '0.00', 'due' => '250.00'],
            ])
            ->assertJsonPath('data.totals.total', '700.00');

        $this->report('/sales?group_by=day&sort=due&direction=asc')->assertJsonPath('data.items.0.date', $this->today);
        $this->report('/sales?group_by=day&sort=sale_no')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_sales_sorting_and_pagination(): void
    {
        $totals = fn (string $q) => collect($this->report("/sales{$q}")->assertOk()->json('data.items'))->pluck('total')->all();
        $this->assertSame(['200.00', '250.00', '250.00'], $totals('?sort=total&direction=asc'));
        $this->assertSame(['200.00', '50.00', '0.00'], collect($this->report('/sales?sort=paid&direction=desc')->json('data.items'))->pluck('paid')->all());
        $this->assertSame('250.00', $this->report('/sales?sort=due&direction=desc')->json('data.items.0.due'));

        $page = $this->report('/sales?per_page=2&page=2')->assertOk();
        $page->assertJsonCount(1, 'data.items')->assertJsonPath('data.pagination.last_page', 2)->assertJsonPath('data.pagination.total', 3);
        // Totals always cover the whole filtered set, not the page.
        $page->assertJsonPath('data.totals.total', '700.00');

        $this->report('/sales?sort=profit')->assertJsonValidationErrors('sort');
        $this->report('/sales?per_page=101')->assertJsonValidationErrors('per_page');
        $this->report("/sales?date={$this->today}&date_from={$this->today}")->assertJsonValidationErrors('date');
        $this->report("/sales?date_from={$this->today}&date_to={$this->yesterday}")->assertJsonValidationErrors('date_to');
    }

    public function test_sales_rows_load_without_n_plus_one(): void
    {
        DB::enableQueryLog();
        $this->report('/sales?per_page=25')->assertOk()
            ->assertJsonStructure(['data' => ['items' => [['sale_no', 'sold_at', 'branch' => ['code'], 'customer', 'items_count', 'total', 'paid', 'due', 'payment_status']]]]);
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'restaurant_'));
        $this->assertLessThanOrEqual(6, $queries->count());
    }

    // ---- Hall bookings -------------------------------------------------------------------------

    public function test_booking_totals_status_and_date_filtering(): void
    {
        $this->report('/bookings')->assertOk()
            ->assertJsonPath('data.totals', ['count' => 2, 'hall_charges' => '3000.00', 'food_packages' => '0.00', 'booking_total' => '3000.00', 'agreed_amount' => '3000.00', 'paid' => '400.00', 'due' => '2600.00', 'cancelled_count' => 0])
            ->assertJsonPath('data.pagination.total', 2);

        $this->report("/bookings?date={$this->today}")
            ->assertJsonPath('data.totals.agreed_amount', '1000.00')->assertJsonPath('data.items.0.due', '600.00');
        $this->report("/bookings?date_from={$this->inThreeDays}&date_to={$this->inThreeDays}")->assertJsonPath('data.totals.count', 1);

        // Cancelled bookings can be listed but never count as revenue or due.
        $this->report('/bookings?status=cancelled')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.status', 'cancelled')
            ->assertJsonPath('data.items.0.due', '0.00')
            ->assertJsonPath('data.items.0.payment_status', null)
            ->assertJsonPath('data.totals', ['count' => 0, 'hall_charges' => '0.00', 'food_packages' => '0.00', 'booking_total' => '0.00', 'agreed_amount' => '0.00', 'paid' => '0.00', 'due' => '0.00', 'cancelled_count' => 1]);
        $this->report('/bookings?status=confirmed&payment_status=partial')->assertJsonPath('data.totals.count', 1);
        $this->report('/bookings?sort=due&direction=desc')->assertJsonPath('data.items.0.due', '2000.00');
        $this->report('/bookings?status=pending')->assertJsonValidationErrors('status');
    }

    // ---- Expenses ------------------------------------------------------------------------------

    public function test_expenses_daily_category_and_filters(): void
    {
        $this->report('/expenses?group_by=day')->assertOk()
            ->assertJsonPath('data.items', [
                ['date' => $this->today, 'count' => 2, 'total' => '500.00'],
                ['date' => $this->yesterday, 'count' => 1, 'total' => '100.00'],
            ])
            ->assertJsonPath('data.totals', ['count' => 3, 'total' => '600.00', 'paid' => '600.00', 'supplier_due' => '0.00']);

        $this->report('/expenses?group_by=category')
            ->assertJsonPath('data.items.0.category', 'Food Purchase')
            ->assertJsonPath('data.items.0.total', '400.00')
            ->assertJsonPath('data.items.0.count', 2)
            ->assertJsonPath('data.items.1.total', '200.00');
        $this->report('/expenses?group_by=category&sort=category&direction=asc')->assertJsonPath('data.items.0.category', 'Food Purchase');

        $this->report("/expenses?group_by=category&date={$this->today}")
            ->assertJsonPath('data.totals.total', '500.00')->assertJsonPath('data.items.0.total', '300.00');
        $this->report("/expenses?category_id={$this->food->id}")->assertJsonPath('data.totals.total', '400.00');
        $this->report('/expenses')->assertJsonPath('data.group_by', 'day');
        $this->report('/expenses?group_by=supplier')->assertJsonValidationErrors('group_by');
    }

    // ---- Branches ------------------------------------------------------------------------------

    public function test_branch_filtering_and_isolation(): void
    {
        $this->report("/sales?branch_id={$this->branchB->id}")->assertJsonPath('data.totals.count', 0);
        $this->report("/expenses?branch_id={$this->branchB->id}")->assertJsonPath('data.totals.total', '0.00');
        $this->report("/sales?branch_id={$this->branchA->id}")->assertJsonPath('data.totals.total', '700.00');

        $global = $this->userWith(['restaurant.report.view', 'restaurant.sale.view', 'restaurant.booking.view', 'restaurant.expense.view', 'branch.access_all']);
        $this->report('/sales', $global)->assertJsonPath('data.totals', ['count' => 4, 'total' => '800.00', 'paid' => '350.00', 'due' => '450.00']);
        $this->report('/expenses', $global)->assertJsonPath('data.totals.total', '1599.00');
        $this->report("/expenses?branch_id={$this->branchB->id}", $global)->assertJsonPath('data.totals.total', '999.00');
        $this->report("/summary?date={$this->today}", $global)
            ->assertJsonPath('data.food_sales.revenue', '550.00')
            ->assertJsonPath('data.food_sales.collected_in_period', '350.00')
            ->assertJsonPath('data.expenses.total', '1499.00');
        $this->report("/summary?date={$this->today}&branch_id={$this->branchB->id}", $global)
            ->assertJsonPath('data.food_sales.revenue', '100.00')
            ->assertJsonPath('data.hall_bookings.revenue', '0.00');

        $onlyB = $this->userWith(['restaurant.report.view', 'restaurant.sale.view', 'restaurant.booking.view', 'restaurant.expense.view'], [$this->branchB]);
        $this->report("/summary?date={$this->today}", $onlyB)
            ->assertJsonPath('data.food_sales.revenue', '100.00')
            ->assertJsonPath('data.food_sales.collected_in_period', '100.00')
            ->assertJsonPath('data.hall_bookings.collected_in_period', '0.00')
            ->assertJsonPath('data.expenses.total', '999.00');
        $this->report("/sales?branch_id={$this->branchA->id}", $onlyB)->assertJsonPath('data.totals.count', 0);
    }

    // ---- Summary -------------------------------------------------------------------------------

    public function test_financial_summary_keeps_streams_separate(): void
    {
        $summary = $this->report("/summary?date={$this->today}")->assertOk()->json('data');

        $this->assertSame(['date_from' => $this->today, 'date_to' => $this->today], $summary['period']);
        $this->assertSame(['count' => 2, 'revenue' => '450.00', 'received' => '250.00', 'outstanding_due' => '200.00', 'collected_in_period' => '250.00'], $summary['food_sales']);
        $this->assertSame(['count' => 1, 'revenue' => '1000.00', 'hall_charges' => '1000.00', 'food_packages' => '0.00', 'food_package_count' => 0, 'received' => '400.00', 'outstanding_due' => '600.00', 'collected_in_period' => '400.00'], $summary['hall_bookings']);
        $this->assertSame(['count' => 2, 'total' => '500.00', 'paid' => '500.00', 'supplier_due' => '0.00'], $summary['expenses']);
        // No profit/net figure: the restaurant domain defines no cost model.
        foreach (['profit', 'net', 'net_income', 'balance'] as $key) {
            $this->assertArrayNotHasKey($key, $summary);
        }

        $range = $this->report("/summary?date_from={$this->yesterday}&date_to={$this->inThreeDays}")->json('data');
        $this->assertSame('700.00', $range['food_sales']['revenue']);
        $this->assertSame('3000.00', $range['hall_bookings']['revenue']);
        $this->assertSame('2600.00', $range['hall_bookings']['outstanding_due']);
        $this->assertSame('600.00', $range['expenses']['total']);

        // Collections follow the payment date, not the sale date, and ignore reversed payments.
        $yesterdaySale = FoodSale::where('total_minor', 25000)->whereNull('reversed_at')
            ->where('sold_at', '<', now()->startOfDay())->sole();
        $this->actingAs($this->manager)->postJson("/api/v1/restaurant/sales/{$yesterdaySale->id}/payments", [
            'payment_date' => $this->yesterday, 'amount' => '30', 'method' => 'cash',
        ])->assertForbidden(); // the manager may view/create sales but not record payments
        $collector = $this->userWith(['restaurant.sale_payment.create', 'restaurant.sale_payment.reverse'], [$this->branchA]);
        $this->actingAs($collector)->postJson("/api/v1/restaurant/sales/{$yesterdaySale->id}/payments", [
            'payment_date' => $this->yesterday, 'amount' => '30', 'method' => 'cash',
        ])->assertCreated();
        $this->report("/summary?date={$this->today}")->assertJsonPath('data.food_sales.collected_in_period', '250.00');
        $this->report("/summary?date={$this->yesterday}")
            ->assertJsonPath('data.food_sales.collected_in_period', '30.00')
            ->assertJsonPath('data.food_sales.received', '30.00');
        $paymentId = $yesterdaySale->payments()->sole()->id;
        $this->actingAs($collector)->postJson("/api/v1/restaurant/sales/{$yesterdaySale->id}/payments/{$paymentId}/reverse", ['reason' => 'Wrong sale'])->assertOk();
        $this->report("/summary?date={$this->yesterday}")->assertJsonPath('data.food_sales.collected_in_period', '0.00');

        // Default period: this month up to today.
        $this->report('/summary')->assertJsonPath('data.period', ['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => $this->today]);
        $this->report("/summary?date_from={$this->today}")->assertJsonValidationErrors('date_to');
    }

    public function test_reports_match_operational_figures(): void
    {
        // Report totals are the same numbers the sales, bookings and expenses screens show.
        $sales = $this->actingAs($this->manager)->getJson('/api/v1/restaurant/sales?state=active')->json('data.summary');
        $this->report('/sales')->assertJsonPath('data.totals.total', $sales['total'])->assertJsonPath('data.totals.due', $sales['due']);

        $bookings = $this->actingAs($this->manager)->getJson('/api/v1/restaurant/hall-bookings')->json('data.summary');
        $this->report('/bookings')->assertJsonPath('data.totals.agreed_amount', $bookings['agreed_amount'])->assertJsonPath('data.totals.due', $bookings['due']);

        $daily = $this->actingAs($this->manager)->getJson("/api/v1/restaurant/expense-summary?date_from={$this->yesterday}&date_to={$this->today}")->json('data');
        $this->report('/expenses')->assertJsonPath('data.totals.total', $daily['total']);

        // Revenue = received + outstanding due, for both streams.
        $summary = $this->report("/summary?date_from={$this->yesterday}&date_to={$this->inThreeDays}")->json('data');
        foreach (['food_sales', 'hall_bookings'] as $stream) {
            $toMinor = fn (string $v) => (int) str_replace('.', '', $v);
            $this->assertSame($toMinor($summary[$stream]['revenue']), $toMinor($summary[$stream]['received']) + $toMinor($summary[$stream]['outstanding_due']));
        }
    }

    // ---- Authorization -------------------------------------------------------------------------

    public function test_authorization(): void
    {
        $this->app['auth']->forgetGuards();
        foreach (['/sales', '/bookings', '/expenses', '/summary'] as $path) {
            $this->getJson(self::REPORTS.$path)->assertUnauthorized();
        }

        // Operational permissions alone do not grant reports.
        $operator = $this->userWith(['restaurant.sale.view', 'restaurant.booking.view', 'restaurant.expense.view', 'car.report.view'], [$this->branchA]);
        foreach (['/sales', '/bookings', '/expenses', '/summary'] as $path) {
            $this->report($path, $operator)->assertForbidden();
        }

        // The report permission alone does not reveal any area.
        $reporter = $this->userWith(['restaurant.report.view'], [$this->branchA]);
        foreach (['/sales', '/bookings', '/expenses'] as $path) {
            $this->report($path, $reporter)->assertForbidden();
        }
        $this->report("/summary?date={$this->today}", $reporter)->assertOk()
            ->assertJsonPath('data.food_sales', null)->assertJsonPath('data.hall_bookings', null)->assertJsonPath('data.expenses', null);

        // Each area needs its own view permission.
        $salesOnly = $this->userWith(['restaurant.report.view', 'restaurant.sale.view'], [$this->branchA]);
        $this->report('/sales', $salesOnly)->assertOk();
        $this->report('/bookings', $salesOnly)->assertForbidden();
        $this->report('/expenses', $salesOnly)->assertForbidden();
        $this->report("/summary?date={$this->today}", $salesOnly)
            ->assertJsonPath('data.food_sales.revenue', '450.00')->assertJsonPath('data.hall_bookings', null)->assertJsonPath('data.expenses', null);

        $this->report('/profit')->assertNotFound();
    }
}
