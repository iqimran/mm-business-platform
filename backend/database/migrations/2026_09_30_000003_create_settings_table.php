<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 150)->unique();
            $table->jsonb('value')->nullable();
            $table->string('description')->nullable();
            $table->timestampsTz();
        });

        // Dot-namespaced lowercase keys, e.g. "app.name".
        DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_key_format_check CHECK (key ~ '^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
