<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
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
        $this->addColumn('orders', 'delivered_at', fn (Blueprint $table) => $table->timestamp('delivered_at')->nullable());
        $this->addColumn('orders', 'delivered_by', fn (Blueprint $table) => $table->string('delivered_by')->nullable());
        $this->addColumn('orders', 'delivered_note', fn (Blueprint $table) => $table->text('delivered_note')->nullable());
        $this->addColumn('orders', 'current_location', fn (Blueprint $table) => $table->json('current_location')->nullable());
    }

    public function down(): void
    {
        // Intentionally left empty: these columns may also be created by the base
        // delivery migration on fresh installs, so rollback must not remove live data.
    }

    private function addColumn(string $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $table) => $definition($table));
        }
    }
};
