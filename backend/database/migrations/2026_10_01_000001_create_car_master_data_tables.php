<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Car module master data. Tables are prefixed "car_" to keep the module isolated.
 * Dealers, parties and expense types are shared across branches; cars are branch-scoped.
 */
return new class extends Migration
{
    private const STATUSES = ['PURCHASED', 'IN_STOCK', 'PREPARATION', 'READY_FOR_SALE', 'SOLD', 'COMPLETED'];

    public function up(): void
    {
        Schema::create('car_dealers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable()->unique();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX car_dealers_name_lower_unique ON car_dealers (lower(name))');
        DB::statement("ALTER TABLE car_dealers ADD CONSTRAINT car_dealers_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('car_parties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable()->unique();
            $table->string('email')->nullable();
            $table->string('national_id', 50)->nullable()->unique();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('name');
        });
        DB::statement("ALTER TABLE car_parties ADD CONSTRAINT car_parties_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('car_expense_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX car_expense_types_name_lower_unique ON car_expense_types (lower(name))');
        DB::statement("ALTER TABLE car_expense_types ADD CONSTRAINT car_expense_types_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('cars', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignUlid('dealer_id')->nullable()->constrained('car_dealers')->restrictOnDelete();
            $table->string('brand', 100);
            $table->string('model', 100);
            $table->smallInteger('model_year')->nullable();
            $table->string('color', 50)->nullable();
            $table->string('chassis_number', 50)->unique();
            $table->string('engine_number', 50)->nullable()->unique();
            $table->string('registration_number', 30)->nullable()->unique();
            $table->integer('mileage_km')->nullable();
            $table->string('status', 30)->default('PURCHASED');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['branch_id', 'status']);
            $table->index('dealer_id');
            $table->index(['brand', 'model']);
        });
        $statuses = implode(', ', array_map(fn (string $s) => "'{$s}'", self::STATUSES));
        DB::statement("ALTER TABLE cars ADD CONSTRAINT cars_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE cars ADD CONSTRAINT cars_model_year_check CHECK (model_year IS NULL OR model_year BETWEEN 1900 AND 2100)');
        DB::statement('ALTER TABLE cars ADD CONSTRAINT cars_mileage_check CHECK (mileage_km IS NULL OR mileage_km >= 0)');
        // Identifiers are stored normalized (uppercase, no whitespace) so uniqueness is reliable.
        DB::statement("ALTER TABLE cars ADD CONSTRAINT cars_chassis_normalized_check CHECK (chassis_number = upper(chassis_number) AND chassis_number !~ '\\s' AND chassis_number <> '')");
        DB::statement("ALTER TABLE cars ADD CONSTRAINT cars_engine_normalized_check CHECK (engine_number IS NULL OR (engine_number = upper(engine_number) AND engine_number !~ '\\s'))");
        DB::statement('ALTER TABLE cars ADD CONSTRAINT cars_registration_normalized_check CHECK (registration_number IS NULL OR registration_number = upper(registration_number))');
        DB::statement("ALTER TABLE cars ADD CONSTRAINT cars_brand_model_not_blank_check CHECK (btrim(brand) <> '' AND btrim(model) <> '')");

        Schema::create('car_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('car_id')->constrained('cars')->cascadeOnDelete();
            $table->string('disk', 30);
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['car_id', 'sort_order']);
        });
        DB::statement('ALTER TABLE car_images ADD CONSTRAINT car_images_size_check CHECK (size_bytes > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('car_images');
        Schema::dropIfExists('cars');
        Schema::dropIfExists('car_expense_types');
        Schema::dropIfExists('car_parties');
        Schema::dropIfExists('car_dealers');
    }
};
