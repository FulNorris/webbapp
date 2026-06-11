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

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'internal_work_order_id')) {
                $table->unsignedBigInteger('internal_work_order_id')->nullable()->index();
            }

            if (! Schema::hasColumn('orders', 'internal_work_order_number')) {
                $table->unsignedInteger('internal_work_order_number')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        foreach (['internal_work_order_id', 'internal_work_order_number'] as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
