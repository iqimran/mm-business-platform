<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Restaurant\Models\ExpenseCategory;
use App\Modules\Restaurant\Models\RestaurantExpense;
use App\Modules\Restaurant\Models\RestaurantExpensePayment;
use App\Modules\Restaurant\Models\RestaurantSupplier;
use App\Modules\Restaurant\Services\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Supplier dues: an expense is the full bill; due = amount − supplier payments (bill-wise).
 * Expenses without a supplier are always fully paid.
 */
class SupplierDuesTest extends RestaurantTestCase
{
    private const EXPENSES = '/api/v1/restaurant/expenses';

    private const ALL = ['restaurant.expense.view', 'restaurant.expense.create', 'restaurant.expense.reverse',
        'restaurant.supplier_payment.create', 'restaurant.supplier_payment.reverse'];

    private User $accountant;

    private ExpenseCategory $category;

    private RestaurantSupplier $fresh;

    private RestaurantSupplier $gas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountant = $this->userWith(self::ALL, [$this->branchA]);
        $this->category = ExpenseCategory::factory()->create(['name' => 'Food Purchase']);
        $this->fresh = RestaurantSupplier::factory()->create(['name' => 'Fresh Foods Ltd', 'contact_person' => 'Abdul']);
        $this->gas = RestaurantSupplier::factory()->create(['name' => 'Gas Supplier']);
    }

    private function bill(array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->accountant)->postJson(self::EXPENSES, $overrides + [
            'branch_id' => $this->branchA->id,
            'category_id' => $this->category->id,
            'supplier_id' => $this->fresh->id,
            'expense_date' => now()->toDateString(),
            'amount' => '10000',
        ]);
    }

    private function pay(string $id, string $amount, array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->accountant)->postJson(self::EXPENSES."/{$id}/payments", $overrides + [
            'payment_date' => now()->toDateString(), 'amount' => $amount, 'method' => 'cash',
        ]);
    }

    // ---- Recording bills -----------------------------------------------------------------------

    public function test_paid_in_full_by_default_partial_and_unpaid(): void
    {
        $this->bill()->assertCreated()
            ->assertJsonPath('data.paid', '10000.00')->assertJsonPath('data.due', '0.00')->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonCount(1, 'data.payments');

        $partial = $this->bill(['paid_amount' => '4000', 'payment_method' => 'mobile_banking', 'payment_reference' => ' BK-1 '])->assertCreated()
            ->assertJsonPath('data.paid', '4000.00')->assertJsonPath('data.due', '6000.00')->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.payments.0.method', 'mobile_banking')->assertJsonPath('data.payments.0.reference', 'BK-1');
        $this->assertSame('4000.00', AuditLog::where('action', 'restaurant.expense.recorded')->where('entity_id', $partial->json('data.id'))->sole()->new_values['paid_now']);

        $this->bill(['paid_amount' => '0'])->assertCreated()
            ->assertJsonPath('data.paid', '0.00')->assertJsonPath('data.due', '10000.00')->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonCount(0, 'data.payments');

        $this->bill(['paid_amount' => '10000.01'])->assertJsonValidationErrors(['paid_amount' => 'The amount paid cannot exceed the expense amount.']);
        foreach (['-1', '1.234', 'abc'] as $bad) {
            $this->bill(['paid_amount' => $bad])->assertJsonValidationErrors('paid_amount');
        }
        $this->bill(['paid_amount' => '100', 'payment_method' => 'gold'])->assertJsonValidationErrors('payment_method');
    }

    public function test_expenses_without_a_supplier_are_always_fully_paid(): void
    {
        $id = $this->bill(['supplier_id' => null])->assertCreated()
            ->assertJsonPath('data.paid', '10000.00')->assertJsonPath('data.due', '0.00')->assertJsonCount(0, 'data.payments')->json('data.id');
        $this->bill(['supplier_id' => null, 'paid_amount' => '0'])
            ->assertJsonValidationErrors(['paid_amount' => 'Select a supplier for an expense that is not fully paid.']);
        $this->pay($id, '1')->assertStatus(409)->assertJsonPath('message', 'This expense has no supplier; it was paid in full when recorded.');
        // No supplier: reversing works as before (no payments to reverse first).
        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$id}/reverse", ['reason' => 'Wrong entry'])->assertOk();
    }

    // ---- Paying bills --------------------------------------------------------------------------

    public function test_paying_a_bill_partially_then_fully_with_audit(): void
    {
        $id = $this->bill(['paid_amount' => '0'])->json('data.id');

        $this->pay($id, '2500.50', ['reference' => 'CHQ-77', 'method' => 'cheque'])->assertCreated()
            ->assertJsonPath('data.due', '7499.50')->assertJsonPath('data.payment_status', 'partial');
        $this->pay($id, '7499.51')->assertJsonValidationErrors(['amount' => 'The amount exceeds the remaining due of 7499.50.']);
        foreach (['0', '-5', '1.234'] as $bad) {
            $this->pay($id, $bad)->assertJsonValidationErrors('amount');
        }
        $this->pay($id, '', ['amount' => 10.5])->assertJsonValidationErrors('amount');
        $this->pay($id, '10', ['payment_date' => now()->addDay()->toDateString()])->assertJsonValidationErrors('payment_date');
        $this->pay($id, '10', ['payment_date' => now()->subDay()->toDateString()])
            ->assertJsonValidationErrors(['payment_date' => 'The payment date cannot be before the expense date.']);

        $this->pay($id, '7499.50')->assertCreated()->assertJsonPath('data.due', '0.00')->assertJsonPath('data.payment_status', 'paid');
        $this->pay($id, '1')->assertStatus(409)->assertJsonPath('message', 'This bill is already fully paid.');

        $logs = AuditLog::where('action', 'restaurant.supplier_payment.recorded')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(['unpaid', 'partial', '7499.50'], [$logs[0]->new_values['payment_status_before'], $logs[0]->new_values['payment_status_after'], $logs[0]->new_values['due_after']]);
        $this->assertSame(['partial', 'paid', '0.00'], [$logs[1]->new_values['payment_status_before'], $logs[1]->new_values['payment_status_after'], $logs[1]->new_values['due_after']]);
        $this->assertSame($this->fresh->id, $logs[0]->new_values['supplier_id']);
        $this->assertSame($this->branchA->id, $logs[0]->branch_id);
    }

    public function test_reversals_raise_the_due_and_protect_the_bill(): void
    {
        $id = $this->bill(['paid_amount' => '4000'])->json('data.id');
        $paymentId = RestaurantExpensePayment::sole()->id;

        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$id}/reverse", ['reason' => 'Wrong bill'])
            ->assertStatus(409)->assertJsonPath('message', 'This expense has supplier payments. Reverse its payments first.');

        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$id}/payments/{$paymentId}/reverse", ['reason' => 'Cheque bounced'])
            ->assertOk()->assertJsonPath('data.due', '10000.00')->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.payments.0.is_reversed', true);
        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$id}/payments/{$paymentId}/reverse", ['reason' => 'Again please'])->assertStatus(409);

        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$id}/reverse", ['reason' => 'Wrong bill'])->assertOk();
        $this->pay($id, '10')->assertStatus(409)->assertJsonPath('message', 'This expense has been reversed and cannot be paid.');

        $recorded = AuditLog::where('action', 'restaurant.supplier_payment.recorded')->sole()->new_values;
        $reversed = AuditLog::where('action', 'restaurant.supplier_payment.reversed')->sole()->new_values;
        $this->assertSame(['unpaid', 'partial'], [$recorded['payment_status_before'], $recorded['payment_status_after']]);
        $this->assertSame(['partial', 'unpaid', '10000.00'], [$reversed['payment_status_before'], $reversed['payment_status_after'], $reversed['due_after']]);
    }

    // ---- Supplier dues overview ----------------------------------------------------------------

    public function test_supplier_dues_overview(): void
    {
        $this->bill(['paid_amount' => '4000', 'expense_date' => now()->subDays(5)->toDateString()]);   // Fresh: due 6000 (oldest)
        $this->bill(['paid_amount' => '0', 'amount' => '2000']);                                       // Fresh: due 2000
        $this->bill(['amount' => '3000']);                                                            // Fresh: paid
        $this->bill(['supplier_id' => $this->gas->id, 'paid_amount' => '500', 'amount' => '1500']);   // Gas: due 1000
        $this->bill(['supplier_id' => null, 'amount' => '999']);                                      // no supplier: not listed

        $data = $this->actingAs($this->accountant)->getJson('/api/v1/restaurant/supplier-dues')->assertOk()->json('data');
        $this->assertSame(['suppliers' => 2, 'billed' => '16500.00', 'paid' => '7500.00', 'due' => '9000.00'], $data['totals']);
        $this->assertSame('Fresh Foods Ltd', $data['items'][0]['supplier']['name']);
        $this->assertSame(['bills' => 3, 'due_bills' => 2, 'billed' => '15000.00', 'paid' => '7000.00', 'due' => '8000.00', 'oldest_due_date' => now()->subDays(5)->toDateString()],
            array_diff_key($data['items'][0], ['supplier' => true]));
        $this->assertSame('1000.00', $data['items'][1]['due']);

        $this->actingAs($this->accountant)->getJson('/api/v1/restaurant/supplier-dues?search=gas')->assertJsonPath('data.pagination.total', 1);

        // A supplier with nothing due is hidden unless asked for.
        $this->bill(['supplier_id' => RestaurantSupplier::factory()->create(['name' => 'Paid Up'])->id, 'amount' => '100']);
        $this->actingAs($this->accountant)->getJson('/api/v1/restaurant/supplier-dues')->assertJsonPath('data.totals.suppliers', 2);
        $this->actingAs($this->accountant)->getJson('/api/v1/restaurant/supplier-dues?only_due=0')->assertJsonPath('data.totals.suppliers', 3);

        // Expense list filters and totals.
        $this->actingAs($this->accountant)->getJson(self::EXPENSES.'?payment_status=due')->assertJsonPath('data.pagination.total', 3);
        $this->actingAs($this->accountant)->getJson(self::EXPENSES.'?payment_status=unpaid')->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($this->accountant)->getJson(self::EXPENSES."?supplier_id={$this->fresh->id}&payment_status=partial")->assertJsonPath('data.pagination.total', 1);
        $this->actingAs($this->accountant)->getJson(self::EXPENSES)->assertJsonPath('data.summary.supplier_due', '9000.00');
    }

    // ---- Voucher -------------------------------------------------------------------------------

    public function test_a4_payment_voucher_with_audit_and_void(): void
    {
        $id = $this->bill(['paid_amount' => '4000', 'reference' => 'INV-55', 'description' => 'Rice and oil'])->json('data.id');
        $payment = RestaurantExpensePayment::sole();
        $url = self::EXPENSES."/{$id}/payments/{$payment->id}/voucher";

        $pdf = $this->actingAs($this->accountant)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringContainsString('/MediaBox [0.000 0.000 595.280 841.890]', $pdf); // A4

        $data = app(PaymentReceipt::class)->expenseVoucherData(RestaurantExpense::find($id), $payment);
        $this->assertSame(['Payment Voucher', 'Paid to', 'Fresh Foods Ltd'], [$data['title'], $data['counterparty_label'], $data['customer']]);
        $this->assertMatchesRegularExpression('/^SV-\d{8}-[0-9A-Z]{6}$/', $data['number']);
        $this->assertSame(['10,000.00', '4,000.00', '6,000.00'], [$data['obligation'], $data['paid_to_date'], $data['outstanding']]);
        $this->assertSame('INV-55', $data['document']['Bill / reference']);
        $this->assertNull($data['void']);

        $this->assertSame(1, AuditLog::where('action', 'restaurant.payment_receipt_printed')->where('entity_type', 'restaurant_expense_payment')->count());

        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$id}/payments/{$payment->id}/reverse", ['reason' => 'Paid twice'])->assertOk();
        $this->assertSame('Paid twice', app(PaymentReceipt::class)->expenseVoucherData(RestaurantExpense::find($id), $payment->fresh())['void']['reason']);
    }

    // ---- Security ------------------------------------------------------------------------------

    public function test_permissions_and_branch_isolation(): void
    {
        $id = $this->bill(['paid_amount' => '1000'])->json('data.id');
        $paymentId = RestaurantExpensePayment::sole()->id;

        $viewer = $this->userWith(['restaurant.expense.view', 'restaurant.expense.create'], [$this->branchA]);
        $this->pay($id, '10', [], $viewer)->assertForbidden();
        $this->actingAs($viewer)->postJson(self::EXPENSES."/{$id}/payments/{$paymentId}/reverse", ['reason' => 'Not allowed'])->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/restaurant/supplier-dues')->assertOk();

        $payer = $this->userWith(['restaurant.supplier_payment.create'], [$this->branchA]);
        $this->pay($id, '10', [], $payer)->assertCreated();
        $this->actingAs($payer)->postJson(self::EXPENSES."/{$id}/payments/{$paymentId}/reverse", ['reason' => 'Not allowed'])->assertForbidden();
        $this->actingAs($this->userWith(['restaurant.supplier.view'], [$this->branchA]))->getJson('/api/v1/restaurant/supplier-dues')->assertForbidden();

        $otherBranch = $this->userWith(self::ALL, [$this->branchB]);
        $this->pay($id, '10', [], $otherBranch)->assertForbidden();
        $this->actingAs($otherBranch)->get(self::EXPENSES."/{$id}/payments/{$paymentId}/voucher")->assertForbidden();
        $this->actingAs($otherBranch)->getJson('/api/v1/restaurant/supplier-dues')->assertJsonPath('data.totals.suppliers', 0);

        // A payment is only reachable through its own bill.
        $other = $this->bill(['paid_amount' => '0'])->json('data.id');
        $this->actingAs($this->accountant)->postJson(self::EXPENSES."/{$other}/payments/{$paymentId}/reverse", ['reason' => 'Wrong bill'])->assertNotFound();

        $this->assertSame(2, RestaurantExpensePayment::active()->count());
    }

    // ---- Integrity -----------------------------------------------------------------------------

    public function test_database_enforces_supplier_payment_integrity(): void
    {
        $id = $this->bill(['paid_amount' => '4000'])->json('data.id');
        $noSupplier = $this->bill(['supplier_id' => null])->json('data.id');
        $payment = RestaurantExpensePayment::sole();
        $row = fn (array $values) => $values + [
            'id' => strtolower((string) Str::ulid()), 'expense_id' => $id, 'branch_id' => $this->branchA->id, 'supplier_id' => $this->fresh->id,
            'payment_date' => now()->toDateString(), 'amount_minor' => 100, 'method' => 'cash', 'recorded_by' => $this->accountant->id,
        ];

        $this->assertRejected(fn () => DB::table('restaurant_expense_payments')->insert($row(['amount_minor' => 600001])), 'exceed');
        $this->assertRejected(fn () => DB::table('restaurant_expense_payments')->insert($row(['supplier_id' => $this->gas->id])), 'its supplier');
        $this->assertRejected(fn () => DB::table('restaurant_expense_payments')->insert($row(['expense_id' => $noSupplier])), 'its supplier');
        $this->assertRejected(fn () => DB::table('restaurant_expense_payments')->insert($row(['branch_id' => $this->branchB->id])), 'foreign key');
        $this->assertRejected(fn () => DB::table('restaurant_expense_payments')->insert($row(['amount_minor' => 0])), 'amount_check');
        $this->assertRejected(fn () => DB::table('restaurant_expense_payments')->where('id', $payment->id)->update(['amount_minor' => 1]), 'immutable');
        $this->assertRejected(fn () => DB::table('restaurant_expenses')->where('id', $id)
            ->update(['reversed_at' => now(), 'reversed_by' => $this->accountant->id, 'reversal_reason' => 'Bypass']), 'active supplier payments');

        $this->assertSame(400000, (int) RestaurantExpensePayment::sum('amount_minor'));
    }

    public function test_failure_part_way_rolls_back_bill_and_payment(): void
    {
        $audit = app(AuditLogger::class);
        $this->mock(AuditLogger::class, function ($mock) use ($audit) {
            $mock->shouldReceive('record')->andReturnUsing(function (string $action, ...$args) use ($audit) {
                if ($action === 'restaurant.supplier_payment.recorded') {
                    throw new RuntimeException('Simulated failure after the payment was written.');
                }

                return $audit->record($action, ...$args);
            });
        });

        $this->bill(['paid_amount' => '4000'])->assertServerError();
        $this->assertSame(0, RestaurantExpense::count());
        $this->assertSame(0, RestaurantExpensePayment::count());
    }

    public function test_reports_show_supplier_dues_separately_from_the_expense_total(): void
    {
        $this->bill(['paid_amount' => '4000']);
        $this->bill(['supplier_id' => null, 'amount' => '500']);

        $reporter = $this->userWith(['restaurant.report.view', 'restaurant.expense.view'], [$this->branchA]);
        $this->actingAs($reporter)->getJson('/api/v1/restaurant/reports/summary?date='.now()->toDateString())
            ->assertJsonPath('data.expenses', ['count' => 2, 'total' => '10500.00', 'paid' => '4500.00', 'supplier_due' => '6000.00']);
        $this->actingAs($reporter)->getJson('/api/v1/restaurant/reports/expenses?group_by=category')
            ->assertJsonPath('data.totals.total', '10500.00')->assertJsonPath('data.totals.supplier_due', '6000.00');
    }
}
