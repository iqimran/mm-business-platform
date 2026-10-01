<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Car financial records. Amounts are integer minor units (e.g. paisa) to avoid float errors.
 * Records are immutable: the only permitted change is a one-time reversal (correction pattern:
 * reverse with a reason, then record a new entry). Enforced by a trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_purchases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('car_id')->constrained('cars')->restrictOnDelete();
            // Branch at the time of recording (financial history stays with its branch).
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUlid('dealer_id')->constrained('car_dealers')->restrictOnDelete();
            $table->date('purchase_date');
            $table->bigInteger('amount_minor');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignUlid('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();

            $table->index(['branch_id', 'purchase_date']);
            $table->index('dealer_id');
        });

        Schema::create('car_expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('car_id')->constrained('cars')->restrictOnDelete();
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUlid('expense_type_id')->constrained('car_expense_types')->restrictOnDelete();
            $table->date('expense_date');
            $table->bigInteger('amount_minor');
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignUlid('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();

            $table->index(['car_id', 'expense_date']);
            $table->index(['branch_id', 'expense_date']);
            $table->index('expense_type_id');
        });

        foreach (['car_purchases', 'car_expenses'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_amount_positive_check CHECK (amount_minor > 0)");
            // Reversal columns are set together or not at all (explicit IS NOT NULL: a NULL check result would pass).
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_reversal_complete_check CHECK (
                (reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
                OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL AND btrim(reversal_reason) <> '')
            )");
        }

        // At most one active (non-reversed) purchase per car.
        DB::statement('CREATE UNIQUE INDEX car_purchases_one_active_per_car ON car_purchases (car_id) WHERE reversed_at IS NULL');
        DB::statement('CREATE INDEX car_expenses_active_by_car ON car_expenses (car_id) WHERE reversed_at IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION car_financial_record_guard() RETURNS trigger AS $$
            DECLARE
                reversal_columns text[] := ARRAY['reversed_at', 'reversed_by', 'reversal_reason'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% records are immutable and cannot be deleted', TG_TABLE_NAME;
                END IF;

                IF OLD.reversed_at IS NOT NULL THEN
                    RAISE EXCEPTION '% record % is already reversed', TG_TABLE_NAME, OLD.id;
                END IF;

                IF NEW.reversed_at IS NULL
                   OR (to_jsonb(NEW) - reversal_columns) <> (to_jsonb(OLD) - reversal_columns) THEN
                    RAISE EXCEPTION '% records are immutable; only a reversal is allowed', TG_TABLE_NAME;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER car_purchases_immutable
                BEFORE UPDATE OR DELETE ON car_purchases
                FOR EACH ROW EXECUTE FUNCTION car_financial_record_guard();

            CREATE TRIGGER car_expenses_immutable
                BEFORE UPDATE OR DELETE ON car_expenses
                FOR EACH ROW EXECUTE FUNCTION car_financial_record_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('car_expenses');
        Schema::dropIfExists('car_purchases');
        DB::unprepared('DROP FUNCTION IF EXISTS car_financial_record_guard()');
    }
};
