<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Car compliance documents (fitness, tax token, insurance, route permit, ...) with expiry dates.
 * Renewals are new rows, so history is kept; the latest expiry per type is the current document.
 */
return new class extends Migration
{
    private const TYPES = ['fitness', 'tax_token', 'insurance', 'route_permit', 'other'];

    public function up(): void
    {
        Schema::table('cars', function (Blueprint $table) {
            $table->date('registration_date')->nullable()->after('registration_number');
        });

        Schema::create('car_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('car_id')->constrained('cars')->cascadeOnDelete();
            $table->string('type', 30);
            // Required for type "other" (e.g. "Pollution certificate").
            $table->string('custom_name', 100)->nullable();
            $table->string('document_number', 100)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date');
            $table->text('notes')->nullable();
            $table->foreignUlid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['car_id', 'type', 'expiry_date']);
            $table->index('expiry_date');
        });

        $types = implode(', ', array_map(fn (string $t) => "'{$t}'", self::TYPES));
        DB::statement("ALTER TABLE car_documents ADD CONSTRAINT car_documents_type_check CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE car_documents ADD CONSTRAINT car_documents_custom_name_check CHECK (
            (type = 'other' AND custom_name IS NOT NULL AND btrim(custom_name) <> '') OR (type <> 'other' AND custom_name IS NULL)
        )");
        DB::statement('ALTER TABLE car_documents ADD CONSTRAINT car_documents_dates_check CHECK (issue_date IS NULL OR expiry_date >= issue_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('car_documents');

        Schema::table('cars', function (Blueprint $table) {
            $table->dropColumn('registration_date');
        });
    }
};
