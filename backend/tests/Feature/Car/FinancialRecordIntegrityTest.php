<?php

namespace Tests\Feature\Car;

use App\Modules\Car\Models\Car;
use App\Modules\Car\Models\CarDealer;
use App\Modules\Car\Models\CarExpense;
use App\Modules\Car\Models\CarExpenseType;
use App\Modules\Car\Models\CarPurchase;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Database-level guarantees that hold even if application code is bypassed.
 */
class FinancialRecordIntegrityTest extends CarTestCase
{
    private Car $car;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->car = Car::factory()->create(['branch_id' => $this->branchA->id]);
        $this->user = User::factory()->create();
    }

    private function purchase(int $amount = 70000000): CarPurchase
    {
        return CarPurchase::create([
            'car_id' => $this->car->id, 'branch_id' => $this->branchA->id,
            'dealer_id' => CarDealer::factory()->create()->id, 'purchase_date' => '2026-09-01',
            'amount_minor' => $amount, 'recorded_by' => $this->user->id,
        ]);
    }

    private function expense(): CarExpense
    {
        return CarExpense::create([
            'car_id' => $this->car->id, 'branch_id' => $this->branchA->id,
            'expense_type_id' => CarExpenseType::factory()->create()->id, 'expense_date' => '2026-09-02',
            'amount_minor' => 100, 'recorded_by' => $this->user->id,
        ]);
    }

    /**
     * Runs a statement expected to fail inside a savepoint, so the surrounding
     * test transaction stays usable (PostgreSQL aborts a transaction after an error).
     */
    private function assertRejected(callable $statement, ?string $messageContains = null): void
    {
        try {
            DB::transaction($statement);
            $this->fail('The database accepted a forbidden change.');
        } catch (QueryException $e) {
            if ($messageContains !== null) {
                $this->assertStringContainsString($messageContains, $e->getMessage());
            }
        }
    }

    public function test_amounts_must_be_positive(): void
    {
        $this->expectException(QueryException::class);
        $this->purchase(0);
    }

    public function test_financial_records_cannot_be_edited(): void
    {
        $purchase = $this->purchase();
        $this->assertRejected(fn () => DB::table('car_purchases')->where('id', $purchase->id)->update(['amount_minor' => 1]), 'immutable');

        $expense = $this->expense();
        $this->assertRejected(fn () => DB::table('car_expenses')->where('id', $expense->id)->update(['amount_minor' => 1]), 'immutable');

        $this->assertSame(70000000, $purchase->fresh()->amount_minor);
    }

    public function test_financial_records_cannot_be_deleted(): void
    {
        $purchase = $this->purchase();

        $this->expectException(QueryException::class);
        DB::table('car_purchases')->where('id', $purchase->id)->delete();
    }

    public function test_reversal_cannot_also_change_the_amount(): void
    {
        $expense = $this->expense();

        $this->expectException(QueryException::class);
        DB::table('car_expenses')->where('id', $expense->id)->update([
            'amount_minor' => 5,
            'reversed_at' => now(), 'reversed_by' => $this->user->id, 'reversal_reason' => 'sneaky',
        ]);
    }

    public function test_reversal_is_one_time_and_complete(): void
    {
        $expense = $this->expense();

        $this->assertRejected(fn () => DB::table('car_expenses')->where('id', $expense->id)
            ->update(['reversed_at' => now(), 'reversed_by' => $this->user->id]));

        DB::table('car_expenses')->where('id', $expense->id)->update([
            'reversed_at' => now(), 'reversed_by' => $this->user->id, 'reversal_reason' => 'Valid reason',
        ]);

        $this->assertRejected(fn () => DB::table('car_expenses')->where('id', $expense->id)
            ->update(['reversal_reason' => 'Changed later']), 'already reversed');
        $this->assertSame('Valid reason', $expense->fresh()->reversal_reason);
    }

    public function test_database_allows_only_one_active_purchase_per_car(): void
    {
        $this->purchase();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->purchase();
    }

    public function test_car_with_financial_records_cannot_be_deleted_at_database_level(): void
    {
        $this->expense();

        $this->expectException(QueryException::class);
        DB::table('cars')->where('id', $this->car->id)->delete();
    }
}
