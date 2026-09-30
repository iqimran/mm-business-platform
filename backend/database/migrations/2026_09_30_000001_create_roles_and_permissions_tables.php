<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('description')->nullable();
            // Protects default roles from deletion; never used for authorization decisions.
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX roles_name_lower_unique ON roles (lower(name))');
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_name_not_blank_check CHECK (btrim(name) <> '')");

        Schema::create('permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 150)->unique();
            $table->string('description')->nullable();
            $table->timestampsTz();
        });

        // Dot-namespaced lowercase names, e.g. "user.view", "car.expense.create".
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_name_format_check CHECK (name ~ '^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+$')");

        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            // A role still assigned to users cannot be deleted.
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete();
            $table->primary(['user_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
