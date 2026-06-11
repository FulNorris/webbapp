<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS current_location jsonb');
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS location_updated_at timestamp(0) without time zone');
        DB::statement('CREATE INDEX IF NOT EXISTS users_visibility_location_updated_index ON users (visibility, location_updated_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_visibility_location_updated_index');

        if (! Schema::hasTable('users')) {
            return;
        }

        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS location_updated_at');
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS current_location');
    }
};
