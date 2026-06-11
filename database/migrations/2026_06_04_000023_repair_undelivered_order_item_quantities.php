<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_items') || ! Schema::hasTable('orders')) {
            return;
        }

        $columns = [
            'levererat_antal' => Schema::hasColumn('order_items', 'levererat_antal'),
            'delivered_quantity' => Schema::hasColumn('order_items', 'delivered_quantity'),
            'remaining_quantity' => Schema::hasColumn('order_items', 'remaining_quantity'),
            'requested_quantity' => Schema::hasColumn('order_items', 'requested_quantity'),
            'delivered_at' => Schema::hasColumn('order_items', 'delivered_at'),
        ];

        $sets = [];
        if ($columns['levererat_antal']) {
            $sets[] = 'levererat_antal = 0';
        }
        if ($columns['delivered_quantity']) {
            $sets[] = 'delivered_quantity = 0';
        }
        if ($columns['remaining_quantity'] && $columns['requested_quantity']) {
            $sets[] = 'remaining_quantity = requested_quantity';
        }
        if ($columns['delivered_at']) {
            $sets[] = 'delivered_at = null';
        }

        if ($sets === []) {
            return;
        }

        DB::statement(
            'update order_items
             set '.implode(', ', $sets).', updated_at = now()
             from orders
             where orders.id = order_items.order_id
               and orders.delivered_at is null
               and coalesce(orders.status, \'\') not in (\'delivered\', \'levererad\')'
        );
    }

    public function down(): void
    {
        // This migration repairs runtime delivery state and is intentionally not reversible.
    }
};
