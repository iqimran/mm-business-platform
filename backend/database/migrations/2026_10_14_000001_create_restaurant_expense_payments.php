<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Supplier dues: payments against restaurant expenses (bills).
 *
 *   Paid = expense amount                       when the expense has no supplier (always paid in full)
 *        = active supplier payments             when it has a supplier
 *   Due  = expense amount − paid                (only suppliers can be owed money)
 *
 * Payments are immutable apart from a one-time reversal. Expenses recorded before this feature are
 * treated as fully paid: supplier expenses get one "paid at entry" payment for their full amount.
 */
return new class extends Migration
{
    private const METHODS = ['cash', 'bank_transfer', 'cheque', 'mobile_banking', 'other'];

    private const MAX_MINOR = 99999999999999;

    public function up(): void
    {
        $max = self::MAX_MINOR;
        $methods = implode(', ', array_map(fn (string $m) => "'{$m}'", self::METHODS));

        // Target of the payments' composite foreign key (payment branch = expense branch).
        DB::statement('CREATE UNIQUE INDEX restaurant_expenses_id_branch_unique ON restaurant_expenses (id, branch_id)');

        Schema::create('restaurant_expense_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('expense_id');
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('restaurant_suppliers')->restrictOnDelete();
            $table->date('payment_date');
            $table->bigInteger('amount_minor');
            $table->string('method', 30);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignUlid('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();

            $table->foreign(['expense_id', 'branch_id'])->references(['id', 'branch_id'])->on('restaurant_expenses')->restrictOnDelete();
            $table->index('expense_id');
            $table->index(['supplier_id', 'payment_date']);
            $table->index(['branch_id', 'payment_date']);
        });

        DB::statement("ALTER TABLE restaurant_expense_payments ADD CONSTRAINT restaurant_expense_payments_amount_check CHECK (amount_minor > 0 AND amount_minor <= {$max})");
        DB::statement("ALTER TABLE restaurant_expense_payments ADD CONSTRAINT restaurant_expense_payments_method_check CHECK (method IN ({$methods}))");
        DB::statement("ALTER TABLE restaurant_expense_payments ADD CONSTRAINT restaurant_expense_payments_reversal_complete_check CHECK (
            (reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
            OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL AND btrim(reversal_reason) <> '')
        )");

        DB::unprepared(<<<'SQL'
            -- Payments belong to the expense's supplier, never exceed the expense, and a reversed expense takes no payments.
            CREATE OR REPLACE FUNCTION restaurant_expense_payment_check() RETURNS trigger AS $$
            DECLARE
                expense_amount bigint;
                expense_supplier text;
                expense_reversed timestamptz;
                paid bigint;
            BEGIN
                SELECT amount_minor, supplier_id, reversed_at INTO expense_amount, expense_supplier, expense_reversed
                    FROM restaurant_expenses WHERE id = NEW.expense_id;

                IF expense_supplier IS NULL OR expense_supplier <> NEW.supplier_id THEN
                    RAISE EXCEPTION 'restaurant expense % payments must belong to its supplier', NEW.expense_id;
                END IF;

                IF NEW.reversed_at IS NULL AND expense_reversed IS NOT NULL THEN
                    RAISE EXCEPTION 'restaurant expense % is reversed and cannot receive payments', NEW.expense_id;
                END IF;

                SELECT coalesce(sum(amount_minor), 0) INTO paid
                    FROM restaurant_expense_payments WHERE expense_id = NEW.expense_id AND reversed_at IS NULL;

                IF paid > expense_amount THEN
                    RAISE EXCEPTION 'restaurant expense % payments (%) exceed its amount (%)', NEW.expense_id, paid, expense_amount;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- An expense with active supplier payments cannot be reversed (reverse the payments first).
            CREATE OR REPLACE FUNCTION restaurant_expense_reversal_check() RETURNS trigger AS $$
            BEGIN
                IF NEW.reversed_at IS NOT NULL AND EXISTS (
                    SELECT 1 FROM restaurant_expense_payments WHERE expense_id = NEW.id AND reversed_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'restaurant expense % has active supplier payments; reverse them first', NEW.id;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER restaurant_expense_payments_immutable BEFORE UPDATE OR DELETE ON restaurant_expense_payments
                FOR EACH ROW EXECUTE FUNCTION restaurant_financial_record_guard();
            CREATE TRIGGER restaurant_expense_payments_within_amount AFTER INSERT OR UPDATE ON restaurant_expense_payments
                FOR EACH ROW EXECUTE FUNCTION restaurant_expense_payment_check();
            CREATE TRIGGER restaurant_expenses_reversal AFTER UPDATE ON restaurant_expenses
                FOR EACH ROW EXECUTE FUNCTION restaurant_expense_reversal_check();
        SQL);

        // Existing supplier expenses were paid when recorded: one payment for the full amount each.
        DB::table('restaurant_expenses')->whereNotNull('supplier_id')->whereNull('reversed_at')->orderBy('id')
            ->each(function ($expense) {
                DB::table('restaurant_expense_payments')->insert([
                    'id' => strtolower((string) Str::ulid()),
                    'expense_id' => $expense->id,
                    'branch_id' => $expense->branch_id,
                    'supplier_id' => $expense->supplier_id,
                    'payment_date' => $expense->expense_date,
                    'amount_minor' => $expense->amount_minor,
                    'method' => 'other',
                    'reference' => 'Paid at entry',
                    'notes' => 'Recorded before supplier dues were tracked.',
                    'recorded_by' => $expense->recorded_by,
                    'created_at' => $expense->created_at,
                ]);
            });
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS restaurant_expenses_reversal ON restaurant_expenses;
        SQL);
        Schema::dropIfExists('restaurant_expense_payments');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS restaurant_expense_reversal_check();
            DROP FUNCTION IF EXISTS restaurant_expense_payment_check();
        SQL);
        DB::statement('DROP INDEX IF EXISTS restaurant_expenses_id_branch_unique');
    }
};
