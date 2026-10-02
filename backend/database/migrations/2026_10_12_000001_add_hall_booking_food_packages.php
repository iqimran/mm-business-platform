<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event food packages for hall bookings.
 *
 *   Food package total = package guest count × price per head
 *   Booking total      = hall charge + food package total (0 without a package)   → restaurant_hall_bookings.agreed_amount_minor
 *   Booking due        = booking total − active payments                           (existing payment rules, unchanged)
 *
 * agreed_amount_minor stays the authoritative booking total, so the existing payment triggers keep working.
 * Packages are part of the booking's agreement: they can change only while the booking is confirmed
 * (history in the audit log); payments stay immutable. Separate from food sales.
 */
return new class extends Migration
{
    private const MAX_MINOR = 99999999999999;

    public function up(): void
    {
        $max = self::MAX_MINOR;

        // 1. Hall charge: existing bookings had only a hall charge, so it equals their agreed amount.
        Schema::table('restaurant_hall_bookings', function (Blueprint $table) {
            $table->bigInteger('hall_charge_minor')->nullable()->after('status');
        });
        // The update guard refuses changes to cancelled bookings; this backfill changes no agreed figures.
        DB::statement('ALTER TABLE restaurant_hall_bookings DISABLE TRIGGER restaurant_hall_bookings_update_check');
        DB::statement('UPDATE restaurant_hall_bookings SET hall_charge_minor = agreed_amount_minor');
        DB::statement('ALTER TABLE restaurant_hall_bookings ENABLE TRIGGER restaurant_hall_bookings_update_check');
        DB::statement('ALTER TABLE restaurant_hall_bookings ALTER COLUMN hall_charge_minor SET NOT NULL');
        DB::statement("ALTER TABLE restaurant_hall_bookings ADD CONSTRAINT restaurant_hall_bookings_hall_charge_check CHECK (hall_charge_minor >= 0 AND hall_charge_minor <= {$max})");

        // 2. One optional package per booking, always in the booking's branch.
        Schema::create('restaurant_hall_booking_food_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('booking_id')->unique();
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name', 150);
            $table->integer('guest_count');
            $table->bigInteger('price_per_head_minor');
            $table->bigInteger('total_minor');
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->foreign(['booking_id', 'branch_id'])->references(['id', 'branch_id'])->on('restaurant_hall_bookings')->restrictOnDelete();
            $table->index('branch_id');
        });
        DB::statement("ALTER TABLE restaurant_hall_booking_food_packages ADD CONSTRAINT restaurant_hall_booking_food_packages_name_check CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE restaurant_hall_booking_food_packages ADD CONSTRAINT restaurant_hall_booking_food_packages_guests_check CHECK (guest_count BETWEEN 1 AND 100000)');
        DB::statement("ALTER TABLE restaurant_hall_booking_food_packages ADD CONSTRAINT restaurant_hall_booking_food_packages_price_check CHECK (price_per_head_minor > 0 AND price_per_head_minor <= {$max})");
        DB::statement('ALTER TABLE restaurant_hall_booking_food_packages ADD CONSTRAINT restaurant_hall_booking_food_packages_total_check CHECK (total_minor = guest_count::bigint * price_per_head_minor)');

        // 3. Selected menu items (name copied when added; the price is per head, not per item).
        Schema::create('restaurant_hall_booking_food_package_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('package_id')->constrained('restaurant_hall_booking_food_packages')->cascadeOnDelete();
            $table->foreignUlid('menu_item_id')->constrained('restaurant_menu_items')->restrictOnDelete();
            $table->string('item_name', 150);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['package_id', 'menu_item_id']);
            $table->index('menu_item_id');
        });
        DB::statement("ALTER TABLE restaurant_hall_booking_food_package_items ADD CONSTRAINT restaurant_hall_booking_food_package_items_name_check CHECK (btrim(item_name) <> '')");

        DB::unprepared(<<<'SQL'
            -- Booking total = hall charge + package total. Deferred to commit so booking and package can change together.
            CREATE OR REPLACE FUNCTION restaurant_booking_total_check() RETURNS trigger AS $$
            DECLARE
                target_id text;
                expected bigint;
                agreed bigint;
            BEGIN
                IF TG_TABLE_NAME = 'restaurant_hall_bookings' THEN
                    target_id := NEW.id;
                ELSIF TG_OP = 'DELETE' THEN
                    target_id := OLD.booking_id;
                ELSE
                    target_id := NEW.booking_id;
                END IF;

                SELECT b.agreed_amount_minor, b.hall_charge_minor + coalesce(p.total_minor, 0)
                    INTO agreed, expected
                    FROM restaurant_hall_bookings b
                    LEFT JOIN restaurant_hall_booking_food_packages p ON p.booking_id = b.id
                    WHERE b.id = target_id;

                IF agreed IS DISTINCT FROM expected THEN
                    RAISE EXCEPTION 'hall booking % total (%) must equal hall charge + food package (%)', target_id, agreed, expected;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- A package always has at least one menu item (checked at commit).
            CREATE OR REPLACE FUNCTION restaurant_booking_package_items_check() RETURNS trigger AS $$
            DECLARE
                target_id text;
            BEGIN
                IF TG_TABLE_NAME = 'restaurant_hall_booking_food_packages' THEN
                    target_id := NEW.id;
                ELSE
                    target_id := OLD.package_id;
                END IF;

                IF EXISTS (SELECT 1 FROM restaurant_hall_booking_food_packages WHERE id = target_id)
                   AND NOT EXISTS (SELECT 1 FROM restaurant_hall_booking_food_package_items WHERE package_id = target_id) THEN
                    RAISE EXCEPTION 'hall booking food package % must contain at least one menu item', target_id;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- Packages and their items change only while the booking is confirmed.
            CREATE OR REPLACE FUNCTION restaurant_booking_package_guard() RETURNS trigger AS $$
            DECLARE
                booking_status text;
                target_package text;
            BEGIN
                IF TG_TABLE_NAME = 'restaurant_hall_booking_food_packages' THEN
                    -- Separate branches: OLD/NEW are not both available for every operation.
                    IF TG_OP = 'DELETE' THEN
                        SELECT status INTO booking_status FROM restaurant_hall_bookings WHERE id = OLD.booking_id;
                    ELSE
                        SELECT status INTO booking_status FROM restaurant_hall_bookings WHERE id = NEW.booking_id;
                    END IF;
                ELSE
                    IF TG_OP = 'DELETE' THEN target_package := OLD.package_id; ELSE target_package := NEW.package_id; END IF;
                    SELECT b.status INTO booking_status FROM restaurant_hall_booking_food_packages p
                        JOIN restaurant_hall_bookings b ON b.id = p.booking_id WHERE p.id = target_package;
                    -- Items removed together with their package: the package guard already ran.
                    IF NOT FOUND THEN
                        RETURN OLD;
                    END IF;
                END IF;

                IF booking_status IS DISTINCT FROM 'confirmed' THEN
                    RAISE EXCEPTION 'the food package of a % booking cannot be changed', booking_status;
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER restaurant_hall_bookings_total AFTER INSERT OR UPDATE ON restaurant_hall_bookings
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restaurant_booking_total_check();
            CREATE CONSTRAINT TRIGGER restaurant_hall_booking_food_packages_total AFTER INSERT OR UPDATE OR DELETE ON restaurant_hall_booking_food_packages
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restaurant_booking_total_check();

            CREATE CONSTRAINT TRIGGER restaurant_hall_booking_food_packages_items AFTER INSERT OR UPDATE ON restaurant_hall_booking_food_packages
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restaurant_booking_package_items_check();
            CREATE CONSTRAINT TRIGGER restaurant_hall_booking_food_package_items_min AFTER DELETE ON restaurant_hall_booking_food_package_items
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION restaurant_booking_package_items_check();

            CREATE TRIGGER restaurant_hall_booking_food_packages_guard BEFORE INSERT OR UPDATE OR DELETE ON restaurant_hall_booking_food_packages
                FOR EACH ROW EXECUTE FUNCTION restaurant_booking_package_guard();
            CREATE TRIGGER restaurant_hall_booking_food_package_items_guard BEFORE INSERT OR UPDATE OR DELETE ON restaurant_hall_booking_food_package_items
                FOR EACH ROW EXECUTE FUNCTION restaurant_booking_package_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_hall_booking_food_package_items');
        Schema::dropIfExists('restaurant_hall_booking_food_packages');
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS restaurant_hall_bookings_total ON restaurant_hall_bookings;
            DROP FUNCTION IF EXISTS restaurant_booking_package_guard();
            DROP FUNCTION IF EXISTS restaurant_booking_package_items_check();
            DROP FUNCTION IF EXISTS restaurant_booking_total_check();
        SQL);
        DB::statement('ALTER TABLE restaurant_hall_bookings DROP CONSTRAINT IF EXISTS restaurant_hall_bookings_hall_charge_check');
        Schema::table('restaurant_hall_bookings', function (Blueprint $table) {
            $table->dropColumn('hall_charge_minor');
        });
    }
};
