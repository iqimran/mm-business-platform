<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Restrict: audited users/branches are deactivated, not deleted, so history stays intact.
            $table->foreignUlid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUlid('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->string('entity_id', 64)->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
            $table->index('action');
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_action_format_check CHECK (action ~ '^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+$')");

        // Audit history is append-only.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_prevent_modification() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_prevent_modification();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_prevent_modification()');
    }
};
