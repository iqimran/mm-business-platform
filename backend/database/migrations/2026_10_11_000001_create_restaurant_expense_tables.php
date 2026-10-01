<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant expenses: configurable categories (shared by all branches) and branch-scoped expense records.
 * Separate from car_expenses and from restaurant revenue (food sales, hall bookings).
 * Expenses are immutable apart from a one-time reversal (restaurant_financial_record_guard()).
 */
return new class extends Migration
{
    private const MAX_MINOR = 99999999999999;

    public function up(): void
    {
        Schema::create('restaurant_expense_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX restaurant_expense_categories_name_lower_unique ON restaurant_expense_categories (lower(name))');
        DB::statement("ALTER TABLE restaurant_expense_categories ADD CONSTRAINT restaurant_expense_categories_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('restaurant_expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUlid('category_id')->constrained('restaurant_expense_categories')->restrictOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained('restaurant_suppliers')->restrictOnDelete();
            $table->date('expense_date');
            $table->bigInteger('amount_minor');
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignUlid('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();

            $table->index(['branch_id', 'expense_date', 'id']);
            $table->index(['expense_date', 'id']);
            $table->index(['category_id', 'expense_date']);
            $table->index('supplier_id');
        });

        $max = self::MAX_MINOR;
        DB::statement("ALTER TABLE restaurant_expenses ADD CONSTRAINT restaurant_expenses_amount_check CHECK (amount_minor > 0 AND amount_minor <= {$max})");
        DB::statement("ALTER TABLE restaurant_expenses ADD CONSTRAINT restaurant_expenses_reversal_complete_check CHECK (
            (reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
            OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL AND btrim(reversal_reason) <> '')
        )");
        // Daily category-wise totals only count active (non-reversed) expenses.
        DB::statement('CREATE INDEX restaurant_expenses_active_daily ON restaurant_expenses (expense_date, category_id) WHERE reversed_at IS NULL');
        DB::unprepared('CREATE TRIGGER restaurant_expenses_immutable BEFORE UPDATE OR DELETE ON restaurant_expenses
            FOR EACH ROW EXECUTE FUNCTION restaurant_financial_record_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_expenses');
        Schema::dropIfExists('restaurant_expense_categories');
    }
};
