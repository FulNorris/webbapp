<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            DB::statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS permission_allow jsonb NOT NULL DEFAULT '[]'::jsonb");
            DB::statement("ALTER TABLE users ADD COLUMN IF NOT EXISTS permission_deny jsonb NOT NULL DEFAULT '[]'::jsonb");
        }

        if (Schema::hasTable('orders')) {
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS packed_at timestamptz');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS packed_by varchar(255)');
            DB::statement('CREATE INDEX IF NOT EXISTS orders_packed_at_index ON orders (packed_at)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            DB::statement('DROP INDEX IF EXISTS orders_packed_at_index');
            DB::statement('ALTER TABLE orders DROP COLUMN IF EXISTS packed_by');
            DB::statement('ALTER TABLE orders DROP COLUMN IF EXISTS packed_at');
        }

        if (Schema::hasTable('users')) {
            DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS permission_deny');
            DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS permission_allow');
        }
    }
};
