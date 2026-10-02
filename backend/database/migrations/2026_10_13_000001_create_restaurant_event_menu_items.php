<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event menu items: a separate master for hall booking food packages. Items have no price of their own
 * (a package is priced per head). Package items now reference this master instead of the food menu.
 *
 * Existing package items are relinked to event menu items created from the names they recorded,
 * so no agreed package changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_event_menu_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX restaurant_event_menu_items_name_lower_unique ON restaurant_event_menu_items (lower(name))');
        DB::statement("ALTER TABLE restaurant_event_menu_items ADD CONSTRAINT restaurant_event_menu_items_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::table('restaurant_hall_booking_food_package_items', function (Blueprint $table) {
            $table->foreignUlid('event_menu_item_id')->nullable()->after('package_id')->constrained('restaurant_event_menu_items')->restrictOnDelete();
        });

        // Relink existing package items (their copied names become event menu items). Package rows of
        // completed/cancelled bookings are locked by a trigger; this relink changes no agreed content.
        DB::statement('ALTER TABLE restaurant_hall_booking_food_package_items DISABLE TRIGGER restaurant_hall_booking_food_package_items_guard');
        DB::statement(<<<'SQL'
            INSERT INTO restaurant_event_menu_items (id, name, is_active, created_at, updated_at)
            SELECT lower(substr(md5(random()::text || n.name), 1, 26)), n.name, true, now(), now()
            FROM (SELECT DISTINCT ON (lower(btrim(item_name))) btrim(item_name) AS name
                  FROM restaurant_hall_booking_food_package_items ORDER BY lower(btrim(item_name)), created_at) n
            ON CONFLICT DO NOTHING
        SQL);
        DB::statement(<<<'SQL'
            UPDATE restaurant_hall_booking_food_package_items i
            SET event_menu_item_id = e.id
            FROM restaurant_event_menu_items e
            WHERE lower(e.name) = lower(btrim(i.item_name))
        SQL);
        DB::statement('ALTER TABLE restaurant_hall_booking_food_package_items ENABLE TRIGGER restaurant_hall_booking_food_package_items_guard');

        Schema::table('restaurant_hall_booking_food_package_items', function (Blueprint $table) {
            $table->dropUnique(['package_id', 'menu_item_id']);
            $table->dropIndex(['menu_item_id']);
            $table->dropConstrainedForeignId('menu_item_id');
        });
        DB::statement('ALTER TABLE restaurant_hall_booking_food_package_items ALTER COLUMN event_menu_item_id SET NOT NULL');
        Schema::table('restaurant_hall_booking_food_package_items', function (Blueprint $table) {
            $table->unique(['package_id', 'event_menu_item_id']);
            $table->index('event_menu_item_id');
        });
    }

    public function down(): void
    {
        // Package items cannot be mapped back to priced food menu items automatically.
        if (DB::table('restaurant_hall_booking_food_package_items')->exists()) {
            throw new RuntimeException('Cannot roll back: food packages use event menu items. Remove them first or restore from a backup.');
        }

        Schema::table('restaurant_hall_booking_food_package_items', function (Blueprint $table) {
            $table->dropUnique(['package_id', 'event_menu_item_id']);
            $table->dropIndex(['event_menu_item_id']);
            $table->dropConstrainedForeignId('event_menu_item_id');
            $table->foreignUlid('menu_item_id')->after('package_id')->constrained('restaurant_menu_items')->restrictOnDelete();
            $table->unique(['package_id', 'menu_item_id']);
            $table->index('menu_item_id');
        });
        Schema::dropIfExists('restaurant_event_menu_items');
    }
};
