<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant halls (branch-scoped master data) and hall bookings with payments.
 *
 * Double booking: an exclusion constraint forbids two non-cancelled bookings of the same hall whose
 * [start, end) time ranges overlap. It is checked by PostgreSQL itself, so concurrent requests cannot both succeed.
 *
 * Booking due = agreed amount − active (non-reversed) payments, never negative.
 */
return new class extends Migration
{
    private const METHODS = ['cash', 'bank_transfer', 'cheque', 'mobile_banking', 'other'];

    private const STATUSES = ['confirmed', 'completed', 'cancelled'];

    private const MAX_MINOR = 99999999999999;

    public function up(): void
    {
        // Trusted extension (PostgreSQL 13+): lets "hall_id WITH =" take part in a GiST exclusion constraint.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('CREATE SEQUENCE IF NOT EXISTS restaurant_booking_no_seq');

        Schema::create('restaurant_halls', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('capacity')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Target of the bookings' composite foreign key (booking branch = hall branch).
            $table->unique(['id', 'branch_id']);
            $table->index(['branch_id', 'name']);
        });
        DB::statement('CREATE UNIQUE INDEX restaurant_halls_branch_name_lower_unique ON restaurant_halls (branch_id, lower(name))');
        DB::statement("ALTER TABLE restaurant_halls ADD CONSTRAINT restaurant_halls_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE restaurant_halls ADD CONSTRAINT restaurant_halls_capacity_check CHECK (capacity IS NULL OR capacity BETWEEN 1 AND 100000)');

        Schema::create('restaurant_hall_bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('booking_no', 20)->unique();
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUlid('hall_id');
            $table->foreignUlid('customer_id')->constrained('restaurant_customers')->restrictOnDelete();
            $table->date('booking_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status', 20)->default('confirmed');
            $table->bigInteger('agreed_amount_minor');
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign(['hall_id', 'branch_id'])->references(['id', 'branch_id'])->on('restaurant_halls')->restrictOnDelete();
            // Target of the payments' composite foreign key (payment branch = booking branch).
            $table->unique(['id', 'branch_id']);
            $table->index(['branch_id', 'booking_date', 'start_time']);
            $table->index(['hall_id', 'booking_date']);
            $table->index('customer_id');
        });

        Schema::create('restaurant_hall_booking_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id');
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

            $table->foreign(['booking_id', 'branch_id'])->references(['id', 'branch_id'])->on('restaurant_hall_bookings')->restrictOnDelete();
            $table->index('booking_id');
            $table->index(['branch_id', 'payment_date']);
        });

        $max = self::MAX_MINOR;
        $methods = implode(', ', array_map(fn (string $m) => "'{$m}'", self::METHODS));
        $statuses = implode(', ', array_map(fn (string $s) => "'{$s}'", self::STATUSES));

        DB::statement("ALTER TABLE restaurant_hall_bookings ADD CONSTRAINT restaurant_hall_bookings_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE restaurant_hall_bookings ADD CONSTRAINT restaurant_hall_bookings_time_check CHECK (end_time > start_time)');
        DB::statement("ALTER TABLE restaurant_hall_bookings ADD CONSTRAINT restaurant_hall_bookings_amount_check CHECK (agreed_amount_minor > 0 AND agreed_amount_minor <= {$max})");
        DB::statement("ALTER TABLE restaurant_hall_bookings ADD CONSTRAINT restaurant_hall_bookings_cancellation_check CHECK (
            (status <> 'cancelled' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
            OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
        )");
        DB::statement("ALTER TABLE restaurant_hall_bookings ADD CONSTRAINT restaurant_hall_bookings_no_overlap
            EXCLUDE USING gist (
                hall_id WITH =,
                tsrange(booking_date + start_time, booking_date + end_time, '[)') WITH &&
            ) WHERE (status <> 'cancelled')");

        DB::statement("ALTER TABLE restaurant_hall_booking_payments ADD CONSTRAINT restaurant_hall_booking_payments_amount_check CHECK (amount_minor > 0 AND amount_minor <= {$max})");
        DB::statement("ALTER TABLE restaurant_hall_booking_payments ADD CONSTRAINT restaurant_hall_booking_payments_method_check CHECK (method IN ({$methods}))");
        DB::statement("ALTER TABLE restaurant_hall_booking_payments ADD CONSTRAINT restaurant_hall_booking_payments_reversal_complete_check CHECK (
            (reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
            OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL AND btrim(reversal_reason) <> '')
        )");

        DB::unprepared(<<<'SQL'
            -- Active payments never exceed the agreed amount; cancelled bookings receive no payments.
            CREATE OR REPLACE FUNCTION restaurant_booking_payment_check() RETURNS trigger AS $$
            DECLARE
                agreed bigint;
                booking_status text;
                paid bigint;
            BEGIN
                SELECT agreed_amount_minor, status INTO agreed, booking_status FROM restaurant_hall_bookings WHERE id = NEW.booking_id;

                IF NEW.reversed_at IS NULL AND booking_status = 'cancelled' THEN
                    RAISE EXCEPTION 'hall booking % is cancelled and cannot receive payments', NEW.booking_id;
                END IF;

                SELECT coalesce(sum(amount_minor), 0) INTO paid
                    FROM restaurant_hall_booking_payments WHERE booking_id = NEW.booking_id AND reversed_at IS NULL;

                IF paid > agreed THEN
                    RAISE EXCEPTION 'hall booking % payments (%) exceed the agreed amount (%)', NEW.booking_id, paid, agreed;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- The agreed amount cannot drop below what was paid; a booking with active payments cannot be cancelled;
            -- cancelled bookings are final.
            CREATE OR REPLACE FUNCTION restaurant_booking_update_check() RETURNS trigger AS $$
            DECLARE
                paid bigint;
            BEGIN
                IF OLD.status = 'cancelled' THEN
                    RAISE EXCEPTION 'hall booking % is cancelled and cannot be changed', OLD.id;
                END IF;

                SELECT coalesce(sum(amount_minor), 0) INTO paid
                    FROM restaurant_hall_booking_payments WHERE booking_id = NEW.id AND reversed_at IS NULL;

                IF NEW.agreed_amount_minor < paid THEN
                    RAISE EXCEPTION 'hall booking % agreed amount (%) is below the amount paid (%)', NEW.id, NEW.agreed_amount_minor, paid;
                END IF;

                IF NEW.status = 'cancelled' AND paid > 0 THEN
                    RAISE EXCEPTION 'hall booking % has active payments; reverse them before cancelling', NEW.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER restaurant_hall_booking_payments_immutable BEFORE UPDATE OR DELETE ON restaurant_hall_booking_payments
                FOR EACH ROW EXECUTE FUNCTION restaurant_financial_record_guard();
            CREATE TRIGGER restaurant_hall_booking_payments_within_amount AFTER INSERT OR UPDATE ON restaurant_hall_booking_payments
                FOR EACH ROW EXECUTE FUNCTION restaurant_booking_payment_check();
            CREATE TRIGGER restaurant_hall_bookings_update_check BEFORE UPDATE ON restaurant_hall_bookings
                FOR EACH ROW EXECUTE FUNCTION restaurant_booking_update_check();
            CREATE TRIGGER restaurant_hall_bookings_no_delete BEFORE DELETE ON restaurant_hall_bookings
                FOR EACH ROW EXECUTE FUNCTION restaurant_immutable_row_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_hall_booking_payments');
        Schema::dropIfExists('restaurant_hall_bookings');
        Schema::dropIfExists('restaurant_halls');
        DB::statement('DROP SEQUENCE IF EXISTS restaurant_booking_no_seq');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS restaurant_booking_update_check();
            DROP FUNCTION IF EXISTS restaurant_booking_payment_check();
        SQL);
    }
};
