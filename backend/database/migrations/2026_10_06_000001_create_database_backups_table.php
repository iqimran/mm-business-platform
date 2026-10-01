<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata of database backups. The SQL itself lives on the backups disk, never in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type', 20);
            $table->string('status', 20)->default('pending');
            $table->string('disk', 50);
            // Relative key inside the disk, e.g. database/database-2026-10-01-020000-01ab….sql
            $table->string('path')->nullable()->unique();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'completed_at']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE database_backups ADD CONSTRAINT database_backups_type_check CHECK (type IN ('automatic', 'manual'))");
        DB::statement("ALTER TABLE database_backups ADD CONSTRAINT database_backups_status_check CHECK (status IN ('pending', 'running', 'completed', 'failed', 'deleted'))");
        // A completed backup always has a verified file.
        DB::statement("ALTER TABLE database_backups ADD CONSTRAINT database_backups_completed_check CHECK (
            status <> 'completed' OR (path IS NOT NULL AND size_bytes > 0 AND checksum_sha256 IS NOT NULL AND completed_at IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};
