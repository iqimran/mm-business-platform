<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\FoodSale;
use App\Modules\Restaurant\Models\FoodSaleItem;
use App\Modules\Restaurant\Models\FoodSalePayment;
use App\Modules\Restaurant\Models\MenuCategory;
use App\Modules\Restaurant\Models\MenuItem;
use App\Modules\Restaurant\Models\RestaurantCustomer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

class FoodSaleTest extends RestaurantTestCase
{
    private const SALES = '/api/v1/restaurant/sales';

    private const ALL = [
        'restaurant.sale.view', 'restaurant.sale.create', 'restaurant.sale.reverse',
        'restaurant.sale_payment.create', 'restaurant.sale_payment.reverse',
    ];

    private User $cashier;

    private RestaurantCustomer $customer;

    private MenuItem $biryani;   // 350.00

    private MenuItem $tea;       // 25.50

    private MenuItem $kebab;     // 125.50

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = $this->userWith(self::ALL, [$this->branchA]);
        $this->customer = RestaurantCustomer::factory()->create(['name' => 'Karim']);
        $category = MenuCategory::factory()->create(['name' => 'Main']);
        $this->biryani = MenuItem::factory()->for($category, 'category')->create(['name' => 'Biryani', 'price_minor' => 35000]);
        $this->tea = MenuItem::factory()->for($category, 'category')->create(['name' => 'Tea', 'price_minor' => 2550]);
        $this->kebab = MenuItem::factory()->for($category, 'category')->create(['name' => 'Kebab', 'price_minor' => 12550]);
    }

    private function sell(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->cashier)->postJson(self::SALES, $overrides + [
            'branch_id' => $this->branchA->id,
            'customer_id' => $this->customer->id,
            'items' => [['menu_item_id' => $this->biryani->id, 'quantity' => 1]],
        ]);
    }

    private function pay(string $saleId, string $amount, array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->cashier)->postJson(self::SALES."/{$saleId}/payments", $overrides + [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'method' => 'cash',
        ]);
    }

    private function assertNothingRecorded(): void
    {
        $this->assertSame(0, FoodSale::count());
        $this->assertSame(0, FoodSaleItem::count());
        $this->assertSame(0, FoodSalePayment::count());
    }

    // ---- Calculations --------------------------------------------------------------------------

    public function test_single_item_sale_captures_menu_price_and_is_unpaid(): void
    {
        $response = $this->sell()->assertCreated()
            ->assertJsonPath('data.total', '350.00')
            ->assertJsonPath('data.paid', '0.00')
            ->assertJsonPath('data.due', '350.00')
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.customer.name', 'Karim')
            ->assertJsonPath('data.branch.id', $this->branchA->id)
            ->assertJsonPath('data.items.0.item_name', 'Biryani')
            ->assertJsonPath('data.items.0.unit_price', '350.00')
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.items.0.line_total', '350.00')
            ->assertJsonCount(0, 'data.payments');

        $this->assertMatchesRegularExpression('/^FS-\d{6}$/', $response->json('data.sale_no'));
        $this->assertSame(35000, FoodSale::sole()->total_minor);
    }

    public function test_multiple_item_sale_computes_line_totals_and_total(): void
    {
        $this->sell(['items' => [
            ['menu_item_id' => $this->kebab->id, 'quantity' => 3],    // 3 × 125.50 = 376.50
            ['menu_item_id' => $this->tea->id, 'quantity' => 4],      // 4 × 25.50  = 102.00
            ['menu_item_id' => $this->biryani->id, 'quantity' => 2],  // 2 × 350.00 = 700.00
        ]])->assertCreated()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.line_total', '376.50')
            ->assertJsonPath('data.items.1.line_total', '102.00')
            ->assertJsonPath('data.items.2.line_total', '700.00')
            ->assertJsonPath('data.total', '1178.50')
            ->assertJsonPath('data.due', '1178.50');

        $this->assertSame(117850, (int) FoodSaleItem::sum('line_total_minor'));
        $this->assertSame([37650, 10200, 70000], FoodSaleItem::orderBy('id')->pluck('line_total_minor')->all());
    }

    public function test_quantity_calculation_and_client_prices_are_ignored(): void
    {
        $this->sell(['items' => [['menu_item_id' => $this->kebab->id, 'quantity' => 7, 'unit_price' => '1.00', 'line_total' => '7.00']], 'total' => '7.00'])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '125.50')
            ->assertJsonPath('data.items.0.line_total', '878.50')
            ->assertJsonPath('data.total', '878.50');
    }

    public function test_historical_sales_keep_their_price_when_the_menu_changes(): void
    {
        $saleId = $this->sell(['items' => [['menu_item_id' => $this->tea->id, 'quantity' => 2]]])->json('data.id');

        $this->tea->update(['price_minor' => 9900, 'name' => 'Masala Tea']);

        $this->actingAs($this->cashier)->getJson(self::SALES."/{$saleId}")
            ->assertJsonPath('data.items.0.item_name', 'Tea')
            ->assertJsonPath('data.items.0.unit_price', '25.50')
            ->assertJsonPath('data.total', '51.00');

        // New sales use the new price.
        $this->sell(['items' => [['menu_item_id' => $this->tea->id, 'quantity' => 2]]])->assertJsonPath('data.total', '198.00');
    }

    // ---- Payments ------------------------------------------------------------------------------

    public function test_full_payment_at_sale_time(): void
    {
        $this->sell(['payment' => ['amount' => '350.00', 'method' => 'cash']])->assertCreated()
            ->assertJsonPath('data.paid', '350.00')
            ->assertJsonPath('data.due', '0.00')
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.payments.0.amount', '350.00')
            ->assertJsonPath('data.payments.0.payment_date', now()->toDateString());

        // Walk-in (no customer) is allowed when paid in full.
        $this->sell(['customer_id' => null, 'payment' => ['amount' => '350', 'method' => 'mobile_banking', 'reference' => 'TX1']])
            ->assertCreated()->assertJsonPath('data.customer', null)->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_partial_payment_then_settlement(): void
    {
        $saleId = $this->sell(['payment' => ['amount' => '100.25', 'method' => 'cash']])
            ->assertCreated()
            ->assertJsonPath('data.paid', '100.25')
            ->assertJsonPath('data.due', '249.75')
            ->assertJsonPath('data.payment_status', 'partial')
            ->json('data.id');

        $this->pay($saleId, '49.75')->assertCreated()->assertJsonPath('data.due', '200.00')->assertJsonPath('data.payment_status', 'partial');
        $this->pay($saleId, '200.00')->assertCreated()
            ->assertJsonPath('data.paid', '350.00')->assertJsonPath('data.due', '0.00')->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonCount(3, 'data.payments');

        $this->pay($saleId, '0.01')->assertStatus(409);
    }

    public function test_unpaid_sale_requires_a_customer(): void
    {
        $this->sell()->assertCreated()->assertJsonPath('data.payment_status', 'unpaid');

        $this->sell(['customer_id' => null])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->sell(['customer_id' => null, 'payment' => ['amount' => '100', 'method' => 'cash']])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->assertSame(1, FoodSale::count());
    }

    public function test_invalid_payments_are_rejected(): void
    {
        // At sale time.
        foreach (['350.01', '0', '-1', 12.5, '1.234', 'abc'] as $amount) {
            $this->sell(['payment' => ['amount' => $amount, 'method' => 'cash']])
                ->assertUnprocessable()->assertJsonValidationErrors('payment.amount');
        }
        $this->sell(['payment' => ['amount' => '10', 'method' => 'barter']])->assertJsonValidationErrors('payment.method');
        $this->sell(['payment' => ['method' => 'cash']])->assertJsonValidationErrors('payment.amount');
        $this->assertNothingRecorded();

        // Later payments.
        $saleId = $this->sell(['payment' => ['amount' => '300', 'method' => 'cash']])->json('data.id');
        $this->pay($saleId, '50.01')->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'The amount exceeds the remaining due of 50.00.']);
        foreach (['0', '-5'] as $amount) {
            $this->pay($saleId, $amount)->assertJsonValidationErrors('amount');
        }
        $this->pay($saleId, '', ['amount' => 10.5])->assertJsonValidationErrors('amount'); // floats are rejected
        $this->pay($saleId, '10', ['method' => 'gold'])->assertJsonValidationErrors('method');
        $this->pay($saleId, '10', ['payment_date' => now()->addDay()->toDateString()])->assertJsonValidationErrors('payment_date');
        $this->pay($saleId, '10', ['payment_date' => now()->subDays(3)->toDateString()])
            ->assertJsonValidationErrors(['payment_date' => 'The payment date cannot be before the sale date.']);

        $this->assertSame(1, FoodSalePayment::count());
        $this->assertSame(30000, (int) FoodSalePayment::sum('amount_minor'));
    }

    // ---- Validation ----------------------------------------------------------------------------

    public function test_sale_validation(): void
    {
        $inactiveItem = MenuItem::factory()->create(['is_active' => false]);
        $hiddenCategory = MenuCategory::factory()->create(['is_active' => false]);
        $itemInHiddenCategory = MenuItem::factory()->for($hiddenCategory, 'category')->create();
        $inactiveCustomer = RestaurantCustomer::factory()->create(['is_active' => false]);

        $this->sell(['items' => []])->assertJsonValidationErrors(['items' => 'Add at least one menu item.']);
        $this->sell(['items' => [['menu_item_id' => $inactiveItem->id, 'quantity' => 1]]])
            ->assertJsonValidationErrors(['items.0.menu_item_id' => 'This menu item is not available.']);
        $this->sell(['items' => [['menu_item_id' => $itemInHiddenCategory->id, 'quantity' => 1]]])
            ->assertJsonValidationErrors(['items.0.menu_item_id' => 'This menu item is not available.']);
        $this->sell(['items' => [['menu_item_id' => '01JAAAAAAAAAAAAAAAAAAAAAAA', 'quantity' => 1]]])->assertJsonValidationErrors('items.0.menu_item_id');
        $this->sell(['items' => [['menu_item_id' => $this->tea->id, 'quantity' => 1], ['menu_item_id' => $this->tea->id, 'quantity' => 2]]])
            ->assertJsonValidationErrors('items.1.menu_item_id');
        foreach ([0, -1, 10000, 1.5, 'two'] as $quantity) {
            $this->sell(['items' => [['menu_item_id' => $this->tea->id, 'quantity' => $quantity]]])->assertJsonValidationErrors('items.0.quantity');
        }
        $this->sell(['customer_id' => $inactiveCustomer->id])->assertJsonValidationErrors(['customer_id' => 'Select an active customer.']);
        $this->sell(['customer_id' => '01JAAAAAAAAAAAAAAAAAAAAAAA'])->assertJsonValidationErrors('customer_id');
        $this->sell(['sold_at' => now()->addHours(2)->format('Y-m-d H:i')])->assertJsonValidationErrors('sold_at');
        $this->sell(['sold_at' => '2026-13-01 10:00'])->assertJsonValidationErrors('sold_at');
        $this->sell(['branch_id' => null])->assertJsonValidationErrors('branch_id');

        $this->assertNothingRecorded();

        $this->sell(['sold_at' => now()->subDay()->format('Y-m-d').' 13:45'])
            ->assertCreated()->assertJsonPath('data.sold_at', now()->subDay()->setTime(13, 45)->toIso8601String());
    }

    public function test_line_total_overflow_is_a_validation_error(): void
    {
        $expensive = MenuItem::factory()->create(['price_minor' => 99_999_999_999_999]);

        $this->sell(['items' => [['menu_item_id' => $expensive->id, 'quantity' => 2]]])->assertJsonValidationErrors('items.0.quantity');
        $this->sell(['items' => [['menu_item_id' => $expensive->id, 'quantity' => 1], ['menu_item_id' => $this->tea->id, 'quantity' => 1]]])
            ->assertJsonValidationErrors('items');
        $this->assertNothingRecorded();
    }

    // ---- Sale state and reversal ---------------------------------------------------------------

    public function test_reversal_rules_and_audit_trail(): void
    {
        $saleId = $this->sell(['payment' => ['amount' => '200', 'method' => 'cash']])->json('data.id');
        $paymentId = FoodSalePayment::sole()->id;

        $this->actingAs($this->cashier)->postJson(self::SALES."/{$saleId}/reverse", ['reason' => 'Customer cancelled'])
            ->assertStatus(409)->assertJsonPath('message', 'This sale has payments. Reverse its payments first.');
        $this->actingAs($this->cashier)->postJson(self::SALES."/{$saleId}/payments/{$paymentId}/reverse", ['reason' => 'no'])
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->cashier)->postJson(self::SALES."/{$saleId}/payments/{$paymentId}/reverse", ['reason' => 'Wrong amount'])
            ->assertOk()
            ->assertJsonPath('data.paid', '0.00')->assertJsonPath('data.due', '350.00')->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payments.0.is_reversed', true)->assertJsonPath('data.payments.0.reversal_reason', 'Wrong amount');
        $this->actingAs($this->cashier)->postJson(self::SALES."/{$saleId}/payments/{$paymentId}/reverse", ['reason' => 'Again please'])
            ->assertStatus(409);

        $this->actingAs($this->cashier)->postJson(self::SALES."/{$saleId}/reverse", ['reason' => 'Customer cancelled'])
            ->assertOk()->assertJsonPath('data.is_reversed', true)->assertJsonPath('data.reversal_reason', 'Customer cancelled');
        $this->actingAs($this->cashier)->postJson(self::SALES."/{$saleId}/reverse", ['reason' => 'Customer cancelled'])->assertStatus(409);
        $this->pay($saleId, '10')->assertStatus(409);

        $this->assertSame([
            'restaurant.sale.created', 'restaurant.sale_payment.recorded', 'restaurant.sale_payment.reversed', 'restaurant.sale.reversed',
        ], AuditLog::orderBy('created_at')->orderBy('id')->pluck('action')->all());

        $created = AuditLog::where('action', 'restaurant.sale.created')->sole();
        $this->assertSame($this->branchA->id, $created->branch_id);
        $this->assertSame('350.00', $created->new_values['total']);
        $this->assertSame('350.00', $created->new_values['items'][0]['unit_price']);
        $this->assertSame('150.00', AuditLog::where('action', 'restaurant.sale_payment.recorded')->sole()->new_values['due_after']);
        $this->assertSame('350.00', AuditLog::where('action', 'restaurant.sale_payment.reversed')->sole()->new_values['due_after']);
    }

    public function test_a_payment_can_only_be_reversed_through_its_own_sale(): void
    {
        $saleA = $this->sell(['payment' => ['amount' => '100', 'method' => 'cash']])->json('data.id');
        $saleB = $this->sell()->json('data.id');
        $payment = FoodSalePayment::sole();

        $this->actingAs($this->cashier)->postJson(self::SALES."/{$saleB}/payments/{$payment->id}/reverse", ['reason' => 'Wrong sale'])->assertNotFound();
        $this->assertFalse($payment->fresh()->isReversed());
        $this->assertNotNull($saleA);
    }

    // ---- Authorization and branch isolation ----------------------------------------------------

    public function test_permission_denial(): void
    {
        $saleId = $this->sell(['payment' => ['amount' => '100', 'method' => 'cash']])->json('data.id');
        $paymentId = FoodSalePayment::sole()->id;

        $this->app['auth']->forgetGuards();
        $this->getJson(self::SALES)->assertUnauthorized();
        $this->postJson(self::SALES, [])->assertUnauthorized();

        // Menu/customer permissions and branch access alone grant nothing.
        $nobody = $this->userWith(['restaurant.menu.view', 'restaurant.customer.view', 'car.sale.view', 'car.sale.create'], [$this->branchA]);
        $this->actingAs($nobody)->getJson(self::SALES)->assertForbidden();
        $this->actingAs($nobody)->getJson(self::SALES."/{$saleId}")->assertForbidden();
        $this->sell([], $nobody)->assertForbidden();

        $viewer = $this->userWith(['restaurant.sale.view'], [$this->branchA]);
        $this->actingAs($viewer)->getJson(self::SALES."/{$saleId}")->assertOk();
        $this->sell([], $viewer)->assertForbidden();
        $this->pay($saleId, '10', [], $viewer)->assertForbidden();
        $this->actingAs($viewer)->postJson(self::SALES."/{$saleId}/reverse", ['reason' => 'Not allowed'])->assertForbidden();
        $this->actingAs($viewer)->postJson(self::SALES."/{$saleId}/payments/{$paymentId}/reverse", ['reason' => 'Not allowed'])->assertForbidden();

        // Recording payments does not include reversing them.
        $collector = $this->userWith(['restaurant.sale.view', 'restaurant.sale_payment.create'], [$this->branchA]);
        $this->pay($saleId, '10', [], $collector)->assertCreated();
        $this->actingAs($collector)->postJson(self::SALES."/{$saleId}/payments/{$paymentId}/reverse", ['reason' => 'Not allowed'])->assertForbidden();

        $this->assertSame(2, FoodSale::find($saleId)->payments()->active()->count());
        $this->assertSame(1, FoodSale::count());
    }

    public function test_branch_isolation(): void
    {
        $saleA = $this->sell(['payment' => ['amount' => '50', 'method' => 'cash']])->json('data.id');
        $paymentA = FoodSalePayment::sole()->id;
        $branchBCashier = $this->userWith(self::ALL, [$this->branchB]);
        $saleB = $this->sell(['branch_id' => $this->branchB->id], $branchBCashier)->assertCreated()->json('data.id');

        // Cannot create in an unassigned branch.
        $this->sell(['branch_id' => $this->branchB->id])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        // Lists only show accessible branches; filtering by another branch returns nothing.
        $this->actingAs($this->cashier)->getJson(self::SALES)
            ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $saleA)
            ->assertJsonPath('data.summary.total', '350.00');
        $this->actingAs($this->cashier)->getJson(self::SALES."?branch_id={$this->branchB->id}")->assertJsonPath('data.pagination.total', 0);

        // No read, payment or reversal across branches.
        $this->actingAs($branchBCashier)->getJson(self::SALES."/{$saleA}")->assertForbidden();
        $this->pay($saleA, '10', [], $branchBCashier)->assertForbidden();
        $this->actingAs($branchBCashier)->postJson(self::SALES."/{$saleA}/reverse", ['reason' => 'Cross branch'])->assertForbidden();
        $this->actingAs($branchBCashier)->postJson(self::SALES."/{$saleA}/payments/{$paymentA}/reverse", ['reason' => 'Cross branch'])->assertForbidden();
        $this->actingAs($this->cashier)->getJson(self::SALES."/{$saleB}")->assertForbidden();

        // Inactive branches lose access; global access sees every branch but cannot sell in an inactive one.
        $global = $this->userWith([...self::ALL, 'branch.access_all']);
        $this->actingAs($global)->getJson(self::SALES)->assertJsonPath('data.pagination.total', 2);
        $this->branchB->update(['is_active' => false]);
        $this->sell(['branch_id' => $this->branchB->id], $global)->assertJsonValidationErrors('branch_id');

        $this->assertSame(1, FoodSale::find($saleA)->payments()->count());
        $this->assertFalse(FoodSale::find($saleA)->isReversed());
    }

    // ---- Transactions and database integrity ---------------------------------------------------

    public function test_failure_mid_sale_rolls_back_everything(): void
    {
        $audit = app(AuditLogger::class);
        $this->mock(AuditLogger::class, function ($mock) use ($audit) {
            $mock->shouldReceive('record')->andReturnUsing(function (string $action, ...$args) use ($audit) {
                if ($action === 'restaurant.sale_payment.recorded') {
                    throw new RuntimeException('Simulated failure after the sale and items were written.');
                }

                return $audit->record($action, ...$args);
            });
        });

        $this->sell(['payment' => ['amount' => '100', 'method' => 'cash']])->assertServerError();

        $this->assertNothingRecorded();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_database_enforces_financial_integrity(): void
    {
        $saleId = $this->sell(['payment' => ['amount' => '100', 'method' => 'cash']])->json('data.id');
        $sale = FoodSale::find($saleId);
        $item = FoodSaleItem::sole();
        $payment = FoodSalePayment::sole();
        $base = fn (array $values) => $values + ['id' => strtolower((string) Str::ulid()), 'recorded_by' => $this->cashier->id];

        // Immutable rows.
        $this->assertRejected(fn () => DB::table('restaurant_sales')->where('id', $saleId)->update(['total_minor' => 1]), 'immutable');
        $this->assertRejected(fn () => DB::table('restaurant_sales')->where('id', $saleId)->delete(), 'immutable');
        $this->assertRejected(fn () => DB::table('restaurant_sale_items')->where('id', $item->id)->update(['quantity' => 5, 'line_total_minor' => 175000]), 'immutable');
        $this->assertRejected(fn () => DB::table('restaurant_sale_items')->where('id', $item->id)->delete(), 'immutable');
        $this->assertRejected(fn () => DB::table('restaurant_sale_payments')->where('id', $payment->id)->update(['amount_minor' => 1]), 'immutable');

        // Line total must equal quantity × unit price.
        $this->assertRejected(fn () => DB::table('restaurant_sale_items')->insert($this->line($saleId, $this->tea, 2, 9999)), 'line_total_check');

        // Total must equal the sum of lines; a sale needs lines (checked at commit, forced here).
        $this->assertRejected(function () use ($saleId) {
            DB::table('restaurant_sale_items')->insert($this->line($saleId, $this->tea, 1, 2550));
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'does not match');
        $this->assertRejected(function () use ($base) {
            DB::table('restaurant_sales')->insert($base(['sale_no' => 'FS-X1', 'branch_id' => $this->branchA->id, 'sold_at' => now(), 'total_minor' => 100]));
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'does not match');

        // Payments: within the total, same branch as the sale, not on a reversed sale.
        $this->assertRejected(fn () => DB::table('restaurant_sale_payments')->insert($base([
            'sale_id' => $saleId, 'branch_id' => $this->branchA->id, 'payment_date' => now()->toDateString(), 'amount_minor' => 25001, 'method' => 'cash',
        ])), 'exceed');
        $this->assertRejected(fn () => DB::table('restaurant_sale_payments')->insert($base([
            'sale_id' => $saleId, 'branch_id' => $this->branchB->id, 'payment_date' => now()->toDateString(), 'amount_minor' => 100, 'method' => 'cash',
        ])), 'foreign key');

        // A sale with active payments cannot be reversed.
        $this->assertRejected(fn () => DB::table('restaurant_sales')->where('id', $saleId)
            ->update(['reversed_at' => now(), 'reversed_by' => $this->cashier->id, 'reversal_reason' => 'Bypass']), 'active payments');

        $this->assertSame(35000, $sale->fresh()->total_minor);
        $this->assertSame(1, FoodSaleItem::count());
    }

    private function line(string $saleId, MenuItem $item, int $quantity, int $lineTotal): array
    {
        return [
            'id' => strtolower((string) Str::ulid()),
            'sale_id' => $saleId,
            'menu_item_id' => $item->id,
            'item_name' => $item->name,
            'unit_price_minor' => $item->price_minor,
            'quantity' => $quantity,
            'line_total_minor' => $lineTotal,
        ];
    }

    // ---- Listing -------------------------------------------------------------------------------

    public function test_listing_filters_and_summary(): void
    {
        $paid = $this->sell(['payment' => ['amount' => '350', 'method' => 'cash']])->json('data.id');
        $partial = $this->sell(['payment' => ['amount' => '100', 'method' => 'cash']])->json('data.id');
        $unpaid = $this->sell(['items' => [['menu_item_id' => $this->tea->id, 'quantity' => 2]]])->json('data.id');
        $old = $this->sell(['sold_at' => now()->subDays(10)->format('Y-m-d').' 09:00'])->json('data.id');
        $reversed = $this->sell()->json('data.id');
        $this->actingAs($this->cashier)->postJson(self::SALES."/{$reversed}/reverse", ['reason' => 'Mistake'])->assertOk();

        $list = $this->actingAs($this->cashier)->getJson(self::SALES)->assertOk()
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.items.0.items_count', 1);
        // Summary excludes the reversed sale: 350 + 350 + 51 + 350.
        $list->assertJsonPath('data.summary.count', 4)
            ->assertJsonPath('data.summary.total', '1101.00')
            ->assertJsonPath('data.summary.paid', '450.00')
            ->assertJsonPath('data.summary.due', '651.00');

        $ids = fn (string $query) => collect($this->actingAs($this->cashier)->getJson(self::SALES.$query)->assertOk()->json('data.items'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$paid], $ids('?payment_status=paid'));
        $this->assertSame([$partial], $ids('?payment_status=partial'));
        $this->assertSame(collect([$unpaid, $old])->sort()->values()->all(), $ids('?payment_status=unpaid'));
        $this->assertSame([$reversed], $ids('?state=reversed'));
        $this->assertSame([$old], $ids('?date_to='.now()->subDays(5)->toDateString()));
        $this->assertCount(4, $ids('?date_from='.now()->toDateString().'&date_to='.now()->toDateString()));
        $saleNo = FoodSale::find($unpaid)->sale_no;
        $this->assertSame([$unpaid], $ids("?search={$saleNo}"));
        $this->assertCount(5, $ids('?search=karim'));

        $this->actingAs($this->cashier)->getJson(self::SALES.'?payment_status=overpaid')->assertUnprocessable();
        $this->actingAs($this->cashier)->getJson(self::SALES.'?date_from=2026-10-10&date_to=2026-10-01')->assertUnprocessable();
    }

    public function test_sold_menu_items_cannot_be_deleted(): void
    {
        $this->sell();
        $manager = $this->userWith(['restaurant.menu.delete']);

        $this->actingAs($manager)->deleteJson("/api/v1/restaurant/menu-items/{$this->biryani->id}")->assertStatus(409);
        $this->actingAs($manager)->deleteJson("/api/v1/restaurant/menu-items/{$this->tea->id}")->assertOk();
        $this->assertModelExists($this->biryani);
    }
}
