<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Actions\RecordRestaurantExpense;
use App\Modules\Restaurant\Models\ExpenseCategory;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Models\RestaurantSupplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RestaurantExpenseTest extends RestaurantTestCase
{
    private const EXPENSES = '/api/v1/restaurant/expenses';

    private const SUMMARY = '/api/v1/restaurant/expense-summary';

    private const CATEGORIES = '/api/v1/restaurant/expense-categories';

    private const ALL = ['restaurant.expense.view', 'restaurant.expense.create', 'restaurant.expense.reverse'];

    private User $accountant;

    /** @var array<string, ExpenseCategory> */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountant = $this->userWith(self::ALL, [$this->branchA]);
        foreach (['Food Purchase', 'Utilities', 'Staff', 'Maintenance', 'Other'] as $name) {
            $this->categories[$name] = ExpenseCategory::factory()->create(['name' => $name]);
        }
    }

    private function record(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->accountant)->postJson(self::EXPENSES, $overrides + [
            'branch_id' => $this->branchA->id,
            'category_id' => $this->categories['Food Purchase']->id,
            'expense_date' => now()->toDateString(),
            'amount' => '10000.00',
        ]);
    }

    // ---- Categories (configurable master data) -------------------------------------------------

    public function test_expense_categories_are_configurable_master_data(): void
    {
        $admin = $this->userWith(array_map(fn ($a) => "restaurant.expense_category.{$a}", ['view', 'create', 'update', 'delete']));

        $id = $this->actingAs($admin)->postJson(self::CATEGORIES, ['name' => ' Gas Bill ', 'description' => 'Cooking gas'])
            ->assertCreated()->assertJsonPath('data.name', 'Gas Bill')->assertJsonPath('data.is_active', true)->json('data.id');
        $this->actingAs($admin)->postJson(self::CATEGORIES, ['name' => 'gas BILL'])->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson(self::CATEGORIES, ['name' => ' '])->assertJsonValidationErrors('name');
        $this->actingAs($admin)->putJson(self::CATEGORIES."/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAs($admin)->getJson(self::CATEGORIES.'?is_active=1')->assertJsonPath('data.pagination.total', 5);
        $this->actingAs($admin)->deleteJson(self::CATEGORIES."/{$id}")->assertOk();

        // A used category can only be deactivated.
        $this->record()->assertCreated();
        $this->actingAs($admin)->deleteJson(self::CATEGORIES.'/'.$this->categories['Food Purchase']->id)->assertStatus(409);

        $this->assertSame(
            ['restaurant_expense_category.created', 'restaurant_expense_category.updated', 'restaurant_expense_category.deleted'],
            AuditLog::where('entity_type', 'restaurant_expense_category')->orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );
        $this->actingAs($this->userWith([]))->getJson(self::CATEGORIES)->assertForbidden();
        $this->actingAs($this->userWith(['restaurant.expense_category.view']))->postJson(self::CATEGORIES, ['name' => 'X'])->assertForbidden();
    }

    // ---- Creation and validation ---------------------------------------------------------------

    public function test_expense_creation_with_category_and_audit(): void
    {
        $supplier = RestaurantSupplier::factory()->create(['name' => 'Fresh Foods']);

        $this->record([
            'amount' => '10000.5',
            'supplier_id' => $supplier->id,
            'description' => ' Rice and vegetables ',
            'reference' => ' INV-77 ',
        ])->assertCreated()
            ->assertJsonPath('data.amount', '10000.50')
            ->assertJsonPath('data.category.name', 'Food Purchase')
            ->assertJsonPath('data.supplier.name', 'Fresh Foods')
            ->assertJsonPath('data.branch.id', $this->branchA->id)
            ->assertJsonPath('data.expense_date', now()->toDateString())
            ->assertJsonPath('data.description', 'Rice and vegetables')
            ->assertJsonPath('data.reference', 'INV-77')
            ->assertJsonPath('data.recorded_by.id', $this->accountant->id)
            ->assertJsonPath('data.is_reversed', false);

        $this->assertSame(1000050, RestaurantExpense::sole()->amount_minor);

        $log = AuditLog::where('action', 'restaurant.expense.recorded')->sole();
        $this->assertSame($this->branchA->id, $log->branch_id);
        $this->assertSame($this->accountant->id, $log->user_id);
        $this->assertSame(['10000.50', 'Food Purchase'], [$log->new_values['amount'], $log->new_values['category']]);
    }

    public function test_expense_validation(): void
    {
        $inactive = ExpenseCategory::factory()->create(['is_active' => false]);
        $inactiveSupplier = RestaurantSupplier::factory()->create(['is_active' => false]);

        foreach (['0', '0.00', '-50', 100.5, '1.234', '1,000', 'abc', '', '1000000000000'] as $amount) {
            $this->record(['amount' => $amount])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->record(['category_id' => null])->assertJsonValidationErrors('category_id');
        $this->record(['category_id' => $inactive->id])->assertJsonValidationErrors(['category_id' => 'Select an active expense category.']);
        $this->record(['category_id' => '01JAAAAAAAAAAAAAAAAAAAAAAA'])->assertJsonValidationErrors('category_id');
        $this->record(['supplier_id' => $inactiveSupplier->id])->assertJsonValidationErrors(['supplier_id' => 'Select an active supplier.']);
        $this->record(['expense_date' => now()->addDay()->toDateString()])
            ->assertJsonValidationErrors(['expense_date' => 'The expense date cannot be in the future.']);
        $this->record(['expense_date' => '2026-02-30'])->assertJsonValidationErrors('expense_date');
        $this->record(['expense_date' => ''])->assertJsonValidationErrors('expense_date');
        $this->record(['branch_id' => null])->assertJsonValidationErrors('branch_id');
        $this->record(['description' => str_repeat('x', 256)])->assertJsonValidationErrors('description');
        $this->record(['reference' => str_repeat('x', 101)])->assertJsonValidationErrors('reference');

        $this->assertSame(0, RestaurantExpense::count());
        $this->record(['expense_date' => now()->subDays(3)->toDateString()])->assertCreated();
    }

    // ---- Daily category-wise summary -----------------------------------------------------------

    public function test_daily_category_wise_summary(): void
    {
        $day = now()->toDateString();
        foreach ([['Food Purchase', '6000'], ['Food Purchase', '4000'], ['Utilities', '3000'], ['Staff', '5000'], ['Maintenance', '2000'], ['Other', '500']] as [$name, $amount]) {
            $this->record(['category_id' => $this->categories[$name]->id, 'amount' => $amount])->assertCreated();
        }
        // Reversed expenses do not count.
        $wrong = $this->record(['category_id' => $this->categories['Other']->id, 'amount' => '999'])->json('data.id');
        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$wrong}/reverse", ['reason' => 'Duplicate entry'])->assertOk();

        $summary = $this->actingAs($this->accountant)->getJson(self::SUMMARY."?date={$day}")->assertOk()->json('data');

        $this->assertSame($day, $summary['date_from']);
        $this->assertSame('20500.00', $summary['total']);
        $this->assertSame(6, $summary['count']);
        $this->assertCount(1, $summary['days']);
        $this->assertSame('20500.00', $summary['days'][0]['total']);
        $this->assertSame(
            ['Food Purchase' => '10000.00', 'Maintenance' => '2000.00', 'Other' => '500.00', 'Staff' => '5000.00', 'Utilities' => '3000.00'],
            collect($summary['days'][0]['categories'])->pluck('total', 'category')->all(),
        );
        $this->assertSame(2, collect($summary['days'][0]['categories'])->firstWhere('category', 'Food Purchase')['count']);

        // Defaults to today.
        $this->actingAs($this->accountant)->getJson(self::SUMMARY)->assertJsonPath('data.total', '20500.00')->assertJsonPath('data.date_to', $day);
    }

    public function test_summary_over_a_date_range_and_filters(): void
    {
        $d1 = now()->subDays(2)->toDateString();
        $d2 = now()->subDay()->toDateString();
        $this->record(['expense_date' => $d1, 'amount' => '100'])->assertCreated();
        $this->record(['expense_date' => $d1, 'category_id' => $this->categories['Staff']->id, 'amount' => '200'])->assertCreated();
        $this->record(['expense_date' => $d2, 'amount' => '300'])->assertCreated();
        $this->record(['amount' => '999'])->assertCreated(); // today: outside the range

        $summary = $this->actingAs($this->accountant)->getJson(self::SUMMARY."?date_from={$d1}&date_to={$d2}")->assertOk()->json('data');
        $this->assertSame('600.00', $summary['total']);
        $this->assertSame([$d2, $d1], array_column($summary['days'], 'date'));
        $this->assertSame(['300.00', '300.00'], array_column($summary['days'], 'total'));
        $this->assertSame(['Food Purchase' => '400.00', 'Staff' => '200.00'], collect($summary['categories'])->pluck('total', 'category')->all());

        $staff = $this->categories['Staff']->id;
        $this->actingAs($this->accountant)->getJson(self::SUMMARY."?date_from={$d1}&date_to={$d2}&category_id={$staff}")
            ->assertJsonPath('data.total', '200.00')->assertJsonCount(1, 'data.days');

        $this->actingAs($this->accountant)->getJson(self::SUMMARY.'?date_from='.now()->subDays(400)->toDateString().'&date_to='.now()->toDateString())
            ->assertJsonValidationErrors('date_to');
        $this->actingAs($this->accountant)->getJson(self::SUMMARY."?date_from={$d2}&date_to={$d1}")->assertJsonValidationErrors('date_to');
        $this->actingAs($this->accountant)->getJson(self::SUMMARY."?date_from={$d1}")->assertJsonValidationErrors('date_to');
        $this->actingAs($this->accountant)->getJson(self::SUMMARY."?date={$d1}&date_from={$d1}")->assertJsonValidationErrors('date');
        $this->actingAs($this->accountant)->getJson(self::SUMMARY.'?date='.now()->subYears(3)->toDateString())
            ->assertOk()->assertJsonPath('data.total', '0.00')->assertJsonPath('data.days', []);
    }

    public function test_list_date_filtering_and_totals(): void
    {
        $d1 = now()->subDays(2)->toDateString();
        $a = $this->record(['expense_date' => $d1, 'amount' => '100', 'description' => 'Fish market'])->json('data.id');
        $b = $this->record(['amount' => '250', 'category_id' => $this->categories['Utilities']->id, 'reference' => 'DESCO-55'])->json('data.id');
        $c = $this->record(['amount' => '75'])->json('data.id');
        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$c}/reverse", ['reason' => 'Entered twice'])->assertOk();

        $this->actingAs($this->accountant)->getJson(self::EXPENSES)->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.summary.count', 2)
            ->assertJsonPath('data.summary.total', '350.00');

        $ids = fn (string $q) => collect($this->actingAs($this->accountant)->getJson(self::EXPENSES.$q)->assertOk()->json('data.items'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$a], $ids("?date={$d1}"));
        $this->assertSame([$a], $ids("?date_from={$d1}&date_to={$d1}"));
        $this->assertSame(collect([$b, $c])->sort()->values()->all(), $ids('?date_from='.now()->toDateString()));
        $this->assertSame([$b], $ids('?category_id='.$this->categories['Utilities']->id));
        $this->assertSame([$c], $ids('?state=reversed'));
        $this->assertSame([$a], $ids('?search=fish'));
        $this->assertSame([$b], $ids('?search=desco'));
        $this->actingAs($this->accountant)->getJson(self::EXPENSES."?date={$d1}")->assertJsonPath('data.summary.total', '100.00');
        $this->actingAs($this->accountant)->getJson(self::EXPENSES.'?date=yesterday')->assertUnprocessable();
    }

    // ---- Authorization and branch isolation ----------------------------------------------------

    public function test_authorization(): void
    {
        $id = $this->record()->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->getJson(self::EXPENSES)->assertUnauthorized();
        $this->getJson(self::SUMMARY)->assertUnauthorized();

        // Car expense and restaurant sale permissions grant nothing here.
        $nobody = $this->userWith(['car.expense.view', 'car.expense.create', 'restaurant.sale.view', 'restaurant.expense_category.view'], [$this->branchA]);
        $this->actingAs($nobody)->getJson(self::EXPENSES)->assertForbidden();
        $this->actingAs($nobody)->getJson(self::SUMMARY)->assertForbidden();
        $this->actingAs($nobody)->getJson(self::EXPENSES."/{$id}")->assertForbidden();
        $this->record([], $nobody)->assertForbidden();

        $viewer = $this->userWith(['restaurant.expense.view'], [$this->branchA]);
        $this->actingAs($viewer)->getJson(self::EXPENSES."/{$id}")->assertOk();
        $this->actingAs($viewer)->getJson(self::SUMMARY)->assertOk();
        $this->record([], $viewer)->assertForbidden();
        $this->actingAs($viewer)->postJson(self::EXPENSES."/{$id}/reverse", ['reason' => 'Not allowed'])->assertForbidden();

        $clerk = $this->userWith(['restaurant.expense.create'], [$this->branchA]);
        $this->record([], $clerk)->assertCreated();
        $this->actingAs($clerk)->postJson(self::EXPENSES."/{$id}/reverse", ['reason' => 'Not allowed'])->assertForbidden();

        $this->assertSame(2, RestaurantExpense::active()->count());
    }

    public function test_branch_isolation(): void
    {
        $idA = $this->record(['amount' => '1000'])->json('data.id');
        $accountantB = $this->userWith(self::ALL, [$this->branchB]);
        $idB = $this->record(['branch_id' => $this->branchB->id, 'amount' => '2000'], $accountantB)->assertCreated()->json('data.id');

        $this->record(['branch_id' => $this->branchB->id])->assertJsonValidationErrors('branch_id');

        $this->actingAs($this->accountant)->getJson(self::EXPENSES)->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.id', $idA);
        $this->actingAs($this->accountant)->getJson(self::EXPENSES."?branch_id={$this->branchB->id}")->assertJsonPath('data.pagination.total', 0);
        $this->actingAs($this->accountant)->getJson(self::SUMMARY)->assertJsonPath('data.total', '1000.00');
        $this->actingAs($this->accountant)->getJson(self::SUMMARY."?branch_id={$this->branchB->id}")->assertJsonPath('data.total', '0.00');
        $this->actingAs($this->accountant)->getJson(self::EXPENSES."/{$idB}")->assertForbidden();
        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$idB}/reverse", ['reason' => 'Cross branch'])->assertForbidden();

        $global = $this->userWith([...self::ALL, 'branch.access_all']);
        $this->actingAs($global)->getJson(self::SUMMARY)->assertJsonPath('data.total', '3000.00');
        $this->actingAs($global)->getJson(self::SUMMARY."?branch_id={$this->branchB->id}")->assertJsonPath('data.total', '2000.00');

        $this->branchB->update(['is_active' => false]);
        $this->record(['branch_id' => $this->branchB->id], $global)->assertJsonValidationErrors('branch_id');

        $this->assertFalse(RestaurantExpense::find($idB)->isReversed());
    }

    // ---- Financial integrity -------------------------------------------------------------------

    public function test_expenses_are_corrected_by_reversal_never_rewritten(): void
    {
        $id = $this->record(['amount' => '500'])->json('data.id');

        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$id}/reverse", ['reason' => 'no'])->assertJsonValidationErrors('reason');
        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$id}/reverse", ['reason' => 'Wrong amount entered'])
            ->assertOk()->assertJsonPath('data.is_reversed', true)->assertJsonPath('data.reversal_reason', 'Wrong amount entered')
            ->assertJsonPath('data.reversed_by.id', $this->accountant->id);
        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$id}/reverse", ['reason' => 'Again please'])->assertStatus(409);

        // No edit or delete endpoints, and the database refuses direct changes.
        $this->actingAs($this->accountant)->putJson(self::EXPENSES."/{$id}", ['amount' => '1'])->assertStatus(405);
        $this->actingAs($this->accountant)->deleteJson(self::EXPENSES."/{$id}")->assertStatus(405);
        $other = $this->record(['amount' => '700'])->json('data.id');
        $this->assertRejected(fn () => DB::table('restaurant_expenses')->where('id', $other)->update(['amount_minor' => 1]), 'immutable');
        $this->assertRejected(fn () => DB::table('restaurant_expenses')->where('id', $other)->delete(), 'immutable');
        $this->assertRejected(fn () => DB::table('restaurant_expenses')->where('id', $id)->update(['amount_minor' => 1]), 'already reversed');
        $this->assertRejected(fn () => DB::table('restaurant_expenses')->insert([
            'id' => strtolower((string) Str::ulid()), 'branch_id' => $this->branchA->id, 'category_id' => $this->categories['Other']->id,
            'expense_date' => now()->toDateString(), 'amount_minor' => -100, 'recorded_by' => $this->accountant->id,
        ]), 'amount_check');

        $this->assertSame(
            ['restaurant.expense.recorded', 'restaurant.expense.reversed', 'restaurant.expense.recorded'],
            AuditLog::where('entity_type', 'restaurant_expense')->orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(['50000', '70000'], RestaurantExpense::orderBy('amount_minor')->pluck('amount_minor')->map(fn ($v) => (string) $v)->all());
    }

    public function test_category_deactivated_after_validation_is_refused(): void
    {
        // Simulates a category being deactivated between request validation and saving.
        $category = $this->categories['Utilities'];
        $category->update(['is_active' => false]);

        try {
            app(RecordRestaurantExpense::class)->handle($this->accountant, [
                'branch_id' => $this->branchA->id, 'category_id' => $category->id, 'expense_date' => now()->toDateString(), 'amount' => '10',
            ]);
            $this->fail('An inactive category was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['category_id' => ['Select an active expense category.']], $e->errors());
        }

        $this->assertSame(0, RestaurantExpense::count());
    }

    public function test_failed_expense_is_rolled_back(): void
    {
        $audit = app(AuditLogger::class);
        $this->mock(AuditLogger::class, function ($mock) use ($audit) {
            $mock->shouldReceive('record')->andReturnUsing(function (string $action, ...$args) use ($audit) {
                if ($action === 'restaurant.expense.recorded') {
                    throw new RuntimeException('Simulated failure after the expense row was written.');
                }

                return $audit->record($action, ...$args);
            });
        });

        $this->record()->assertServerError();
        $this->assertSame(0, RestaurantExpense::count());
    }

    public function test_restaurant_expenses_stay_separate_from_other_domains(): void
    {
        $this->record(['amount' => '1234'])->assertCreated();

        $this->assertSame(0, CarExpense::count());
        $this->assertSame(0, DB::table('restaurant_sale_payments')->count());
        $this->assertSame(0, DB::table('restaurant_hall_booking_payments')->count());
        // Car expense types are not restaurant categories.
        $carType = DB::table('car_expense_types')->insertGetId(['id' => strtolower((string) Str::ulid()), 'name' => 'Car wash', 'created_at' => now(), 'updated_at' => now()], 'id');
        $this->record(['category_id' => $carType])->assertJsonValidationErrors('category_id');
    }
}
