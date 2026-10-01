<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for paginated lists at scale (measured on 200k cars / 100k parties):
 * - Trigram (pg_trgm) GIN indexes so "contains" searches (ILIKE '%term%') use an index instead of
 *   scanning the whole table. pg_trgm is a trusted PostgreSQL extension (database owner may create it).
 * - B-tree indexes matching the default car list order (newest first), globally and per branch.
 */
return new class extends Migration
{
    private const TRIGRAM = [
        'cars' => ['brand', 'model', 'chassis_number', 'registration_number'],
        'car_parties' => ['name', 'phone', 'national_id'],
        'car_dealers' => ['name', 'phone'],
        'users' => ['name', 'email'],
    ];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (self::TRIGRAM as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("CREATE INDEX {$table}_{$column}_trgm ON {$table} USING gin ({$column} gin_trgm_ops)");
            }
        }

        DB::statement('CREATE INDEX cars_created_at_id_index ON cars (created_at DESC, id DESC)');
        DB::statement('CREATE INDEX cars_branch_created_at_id_index ON cars (branch_id, created_at DESC, id DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cars_branch_created_at_id_index');
        DB::statement('DROP INDEX IF EXISTS cars_created_at_id_index');

        foreach (self::TRIGRAM as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("DROP INDEX IF EXISTS {$table}_{$column}_trgm");
            }
        }
        // The extension is left installed: other objects may use it and it is harmless.
    }
};
