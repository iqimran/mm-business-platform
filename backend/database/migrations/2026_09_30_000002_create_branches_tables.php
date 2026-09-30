<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_code_format_check CHECK (code ~ '^[A-Z0-9][A-Z0-9_-]*$')");
        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_name_not_blank_check CHECK (btrim(name) <> '')");

        // Which branches a user may access. Enforced server-side by authorization (Task 005).
        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            // A branch with assigned users cannot be deleted.
            $table->foreignUlid('branch_id')->constrained('branches')->restrictOnDelete();
            $table->primary(['user_id', 'branch_id']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
        Schema::dropIfExists('branches');
    }
};
