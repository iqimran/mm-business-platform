<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant module master data. Tables are prefixed "restaurant_" to keep the module isolated
 * from the Car module (no shared tables, no foreign keys to car_* tables).
 * Customers, suppliers, menu categories and menu items are shared by all branches.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('restaurant_customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable()->unique();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('name');
        });
        DB::statement("ALTER TABLE restaurant_customers ADD CONSTRAINT restaurant_customers_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('restaurant_suppliers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 30)->nullable()->unique();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX restaurant_suppliers_name_lower_unique ON restaurant_suppliers (lower(name))');
        DB::statement("ALTER TABLE restaurant_suppliers ADD CONSTRAINT restaurant_suppliers_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('restaurant_menu_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['sort_order', 'name']);
        });
        DB::statement('CREATE UNIQUE INDEX restaurant_menu_categories_name_lower_unique ON restaurant_menu_categories (lower(name))');
        DB::statement("ALTER TABLE restaurant_menu_categories ADD CONSTRAINT restaurant_menu_categories_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE restaurant_menu_categories ADD CONSTRAINT restaurant_menu_categories_sort_order_check CHECK (sort_order BETWEEN 0 AND 9999)');

        Schema::create('restaurant_menu_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->constrained('restaurant_menu_categories')->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            // Selling price in minor units (integer, never floating point). Global for all branches.
            $table->bigInteger('price_minor');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['category_id', 'name']);
        });
        // A dish name is unique within its category (case-insensitive).
        DB::statement('CREATE UNIQUE INDEX restaurant_menu_items_category_name_lower_unique ON restaurant_menu_items (category_id, lower(name))');
        DB::statement("ALTER TABLE restaurant_menu_items ADD CONSTRAINT restaurant_menu_items_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('ALTER TABLE restaurant_menu_items ADD CONSTRAINT restaurant_menu_items_price_check CHECK (price_minor > 0 AND price_minor <= 99999999999999)');

        // "Contains" search (ILIKE '%term%') uses trigram indexes instead of full scans.
        DB::statement('CREATE INDEX restaurant_customers_name_trgm ON restaurant_customers USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX restaurant_customers_phone_trgm ON restaurant_customers USING gin (phone gin_trgm_ops)');
        DB::statement('CREATE INDEX restaurant_suppliers_name_trgm ON restaurant_suppliers USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX restaurant_menu_items_name_trgm ON restaurant_menu_items USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_menu_items');
        Schema::dropIfExists('restaurant_menu_categories');
        Schema::dropIfExists('restaurant_suppliers');
        Schema::dropIfExists('restaurant_customers');
    }
};
