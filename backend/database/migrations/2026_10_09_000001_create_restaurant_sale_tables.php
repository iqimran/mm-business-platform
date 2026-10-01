<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant food sales: branch-scoped, integer minor units, independent of every car_* table.
 *
 * Line total = quantity × unit price (unit price copied from the menu when the sale is made)
 * Sale total = Σ line totals
 * Due        = sale total − active (non-reversed) payments, never negative
 *
 * Sales and payments are immutable apart from a one-time reversal; sale items are fully immutable.
 */
return new class extends Migration
{
    private const METHODS = ['cash', 'bank_transfer', 'cheque', 'mobile_banking', 'other'];

    private const MAX_MINOR = 99999999999999;

    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS restaurant_sale_no_seq');

        Schema::create('restaurant_sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('sale_no', 20)->unique();
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            // Null = walk-in customer (only allowed when fully paid at the time of sale).
            $table->foreignUlid('customer_id')->nullable()->constrained('restaurant_customers')->restrictOnDelete();
            $table->timestampTz('sold_at');
            $table->bigInteger('total_minor');
            $table->text('notes')->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignUlid('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reversal_reason', 500)->nullable();

            // Target of the payments' composite foreign key (payment branch = sale branch).
            $table->unique(['id', 'branch_id']);
            $table->index(['branch_id', 'sold_at', 'id']);
            $table->index(['sold_at', 'id']);
            $table->index('customer_id');
        });

        Schema::create('restaurant_sale_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained('restaurant_sales')->restrictOnDelete();
            $table->foreignUlid('menu_item_id')->constrained('restaurant_menu_items')->restrictOnDelete();
            // Snapshot at the time of sale: later menu changes never alter history.
            $table->string('item_name', 150);
            $table->bigInteger('unit_price_minor');
            $table->integer('quantity');
            $table->bigInteger('line_total_minor');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['sale_id', 'menu_item_id']);
            $table->index('menu_item_id');
        });

        Schema::create('restaurant_sale_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id');
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
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

            $table->foreign(['sale_id', 'branch_id'])->references(['id', 'branch_id'])->on('restaurant_sales')->restrictOnDelete();
            $table->index('sale_id');
            $table->index(['branch_id', 'payment_date']);
        });

        $max = self::MAX_MINOR;
        $methods = implode(', ', array_map(fn (string $m) => "'{$m}'", self::METHODS));

        DB::statement("ALTER TABLE restaurant_sales ADD CONSTRAINT restaurant_sales_total_check CHECK (total_minor > 0 AND total_minor <= {$max})");
        DB::statement('ALTER TABLE restaurant_sale_items ADD CONSTRAINT restaurant_sale_items_quantity_check CHECK (quantity BETWEEN 1 AND 9999)');
        DB::statement("ALTER TABLE restaurant_sale_items ADD CONSTRAINT restaurant_sale_items_price_check CHECK (unit_price_minor > 0 AND unit_price_minor <= {$max})");
        DB::statement('ALTER TABLE restaurant_sale_items ADD CONSTRAINT restaurant_sale_items_line_total_check CHECK (line_total_minor = quantity * unit_price_minor)');
        DB::statement("ALTER TABLE restaurant_sale_items ADD CONSTRAINT restaurant_sale_items_name_not_blank_check CHECK (btrim(item_name) <> '')");
        DB::statement("ALTER TABLE restaurant_sale_payments ADD CONSTRAINT restaurant_sale_payments_amount_check CHECK (amount_minor > 0 AND amount_minor <= {$max})");
        DB::statement("ALTER TABLE restaurant_sale_payments ADD CONSTRAINT restaurant_sale_payments_method_check CHECK (method IN ({$methods}))");

        foreach (['restaurant_sales', 'restaurant_sale_payments'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_reversal_complete_check CHECK (
                (reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
                OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL AND btrim(reversal_reason) <> '')
            )");
        }

        DB::unprepared(<<<'SQL'
            -- Restaurant's own guard (deliberately not shared with the Car module).
            CREATE OR REPLACE FUNCTION restaurant_financial_record_guard() RETURNS trigger AS $$
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

            CREATE OR REPLACE FUNCTION restaurant_immutable_row_guard() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% records are immutable', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            -- A sale has at least one line and its total equals the sum of its lines.
            -- Deferred to commit so the sale and its lines can be inserted in one transaction.
            CREATE OR REPLACE FUNCTION restaurant_sale_totals_check() RETURNS trigger AS $$
            DECLARE
                target_id text;
                expected bigint;
                actual bigint;
                lines integer;
            BEGIN
                -- Separate branches: PL/pgSQL resolves every NEW field referenced in an expression.
                IF TG_TABLE_NAME = 'restaurant_sales' THEN
                    target_id := NEW.id;
                ELSE
                    target_id := NEW.sale_id;
                END IF;

                SELECT total_minor INTO expected FROM restaurant_sales WHERE id = target_id;
                SELECT count(*), coalesce(sum(line_total_minor), 0) INTO lines, actual
                    FROM restaurant_sale_items WHERE sale_id = target_id;

                IF lines = 0 OR actual <> expected THEN
                    RAISE EXCEPTION 'restaurant sale % total (%) does not match its % line(s) (%)', target_id, expected, lines, actual;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- Active payments never exceed the sale total, and a reversed sale cannot receive payments.
            CREATE OR REPLACE FUNCTION restaurant_sale_payment_check() RETURNS trigger AS $$
            DECLARE
                sale_total bigint;
                sale_reversed timestamptz;
                paid bigint;
            BEGIN
                SELECT total_minor, reversed_at INTO sale_total, sale_reversed FROM restaurant_sales WHERE id = NEW.sale_id;

                IF NEW.reversed_at IS NULL AND sale_reversed IS NOT NULL THEN
                    RAISE EXCEPTION 'restaurant sale % is reversed and cannot receive payments', NEW.sale_id;
                END IF;

                SELECT coalesce(sum(amount_minor), 0) INTO paid
                    FROM restaurant_sale_payments WHERE sale_id = NEW.sale_id AND reversed_at IS NULL;

                IF paid > sale_total THEN
                    RAISE EXCEPTION 'restaurant sale % payments (%) exceed its total (%)', NEW.sale_id, paid, sale_total;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- A sale with active payments cannot be reversed (payments must be reversed first).
            CREATE OR REPLACE FUNCTION restaurant_sale_reversal_check() RETURNS trigger AS $$
            BEGIN
                IF NEW.reversed_at IS NOT NULL AND EXISTS (
                    SELECT 1 FROM restaurant_sale_payments WHERE sale_id = NEW.id AND reversed_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'restaurant sale % has active payments; reverse them first', NEW.id;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER restaurant_sales_immutable BEFORE UPDATE OR DELETE ON restaurant_sales
                FOR EACH ROW EXECUTE FUNCTION restaurant_financial_record_guard();
            CREATE TRIGGER restaurant_sale_payments_immutable BEFORE UPDATE OR DELETE ON restaurant_sale_payments
                FOR EACH ROW EXECUTE FUNCTION restaurant_financial_record_guard();
            CREATE TRIGGER restaurant_sale_items_immutable BEFORE UPDATE OR DELETE ON restaurant_sale_items
                FOR EACH ROW EXECUTE FUNCTION restaurant_immutable_row_guard();

            CREATE CONSTRAINT TRIGGER restaurant_sales_totals AFTER INSERT ON restaurant_sales
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restaurant_sale_totals_check();
            CREATE CONSTRAINT TRIGGER restaurant_sale_items_totals AFTER INSERT ON restaurant_sale_items
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restaurant_sale_totals_check();

            CREATE TRIGGER restaurant_sale_payments_within_total AFTER INSERT OR UPDATE ON restaurant_sale_payments
                FOR EACH ROW EXECUTE FUNCTION restaurant_sale_payment_check();
            CREATE TRIGGER restaurant_sales_reversal AFTER UPDATE ON restaurant_sales
                FOR EACH ROW EXECUTE FUNCTION restaurant_sale_reversal_check();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_sale_payments');
        Schema::dropIfExists('restaurant_sale_items');
        Schema::dropIfExists('restaurant_sales');
        DB::statement('DROP SEQUENCE IF EXISTS restaurant_sale_no_seq');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS restaurant_sale_reversal_check();
            DROP FUNCTION IF EXISTS restaurant_sale_payment_check();
            DROP FUNCTION IF EXISTS restaurant_sale_totals_check();
            DROP FUNCTION IF EXISTS restaurant_immutable_row_guard();
            DROP FUNCTION IF EXISTS restaurant_financial_record_guard();
        SQL);
    }
};
