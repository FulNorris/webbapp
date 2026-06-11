<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS assigned_driver_id varchar(255)');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS driver_id varchar(255)');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS driver_name varchar(255)');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_enabled boolean NOT NULL DEFAULT false');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_token varchar(255)');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_url varchar(255)');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_session_id varchar(255)');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS started_at timestamp(0) without time zone');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS last_stopped_at timestamp(0) without time zone');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivered_by varchar(255)');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivered_note text');
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS current_location jsonb');
            DB::statement('CREATE INDEX IF NOT EXISTS orders_assigned_driver_id_index ON orders (assigned_driver_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS orders_driver_id_index ON orders (driver_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS orders_tracking_token_index ON orders (tracking_token)');

            return;
        }

        $this->addColumn('orders', 'assigned_driver_id', fn (Blueprint $table) => $table->string('assigned_driver_id')->nullable()->index());
        $this->addColumn('orders', 'driver_id', fn (Blueprint $table) => $table->string('driver_id')->nullable()->index());
        $this->addColumn('orders', 'driver_name', fn (Blueprint $table) => $table->string('driver_name')->nullable());
        $this->addColumn('orders', 'tracking_enabled', fn (Blueprint $table) => $table->boolean('tracking_enabled')->default(false));
        $this->addColumn('orders', 'tracking_token', fn (Blueprint $table) => $table->string('tracking_token')->nullable()->index());
        $this->addColumn('orders', 'tracking_url', fn (Blueprint $table) => $table->string('tracking_url')->nullable());
        $this->addColumn('orders', 'tracking_session_id', fn (Blueprint $table) => $table->string('tracking_session_id')->nullable());
        $this->addColumn('orders', 'started_at', fn (Blueprint $table) => $table->timestamp('started_at')->nullable());
        $this->addColumn('orders', 'last_stopped_at', fn (Blueprint $table) => $table->timestamp('last_stopped_at')->nullable());
        $this->addColumn('orders', 'delivered_by', fn (Blueprint $table) => $table->string('delivered_by')->nullable());
        $this->addColumn('orders', 'delivered_note', fn (Blueprint $table) => $table->text('delivered_note')->nullable());
        $this->addColumn('orders', 'current_location', fn (Blueprint $table) => $table->json('current_location')->nullable());
    }

    public function down(): void
    {
        // No destructive rollback for live tracking columns.
    }

    private function addColumn(string $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $table) => $definition($table));
        }
    }
};
