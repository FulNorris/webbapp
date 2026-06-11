<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('work_order_delivery_events')) {
            return;
        }

        Schema::table('work_order_delivery_events', function (Blueprint $table) {
            if (! Schema::hasColumn('work_order_delivery_events', 'order_item_id')) {
                $table->unsignedBigInteger('order_item_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('work_order_delivery_events') || ! Schema::hasColumn('work_order_delivery_events', 'order_item_id')) {
            return;
        }

        Schema::table('work_order_delivery_events', fn (Blueprint $table) => $table->dropColumn('order_item_id'));
    }
};
