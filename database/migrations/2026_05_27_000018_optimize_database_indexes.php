<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            DB::statement('CREATE INDEX IF NOT EXISTS orders_created_at_desc_index ON orders (created_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS orders_status_created_at_index ON orders (status, created_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS orders_updated_at_desc_index ON orders (updated_at DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS orders_mottagare_adress_updated_index ON orders (mottagare, adress, updated_at DESC)');
        }

        if (Schema::hasTable('order_items')) {
            DB::statement('CREATE INDEX IF NOT EXISTS order_items_order_id_sort_index ON order_items (order_id, sort_order, id)');
            DB::statement('CREATE INDEX IF NOT EXISTS order_items_order_article_delivery_index ON order_items (arbetsorder_nr, artikel_normalized, delivered_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS order_items_work_order_article_delivery_index ON order_items (work_order_number, artikel_normalized, delivered_at)');
        }

        if (Schema::hasTable('arbetsordrar')) {
            DB::statement('CREATE INDEX IF NOT EXISTS arbetsordrar_updated_at_desc_index ON arbetsordrar (updated_at DESC, id DESC)');
        }

        if (Schema::hasTable('arbetsorder_rader')) {
            DB::statement('CREATE INDEX IF NOT EXISTS arbetsorder_rader_order_sort_index ON arbetsorder_rader (arbetsorder_id, rad_nr, id)');
            DB::statement('CREATE INDEX IF NOT EXISTS arbetsorder_rader_order_article_index ON arbetsorder_rader (arbetsorder_nr, artikel_normalized, rad_nr)');
        }

        if (Schema::hasTable('products')) {
            DB::statement('CREATE INDEX IF NOT EXISTS products_lower_sku_index ON products (lower(sku))');
            DB::statement('CREATE INDEX IF NOT EXISTS products_sku_image_index ON products (sku, primary_image_path, primary_image_url)');
        }

        if (Schema::hasTable('purchases')) {
            DB::statement('CREATE INDEX IF NOT EXISTS purchases_created_at_desc_index ON purchases (created_at DESC, id DESC)');
            DB::statement('CREATE INDEX IF NOT EXISTS purchases_status_created_at_index ON purchases (status, created_at DESC)');
        }

        if (Schema::hasTable('work_order_delivery_events')) {
            DB::statement('CREATE INDEX IF NOT EXISTS work_order_delivery_events_work_order_index ON work_order_delivery_events (work_order_number, delivered_at DESC)');
        }

        if (Schema::hasTable('tracking_links')) {
            DB::statement('CREATE INDEX IF NOT EXISTS tracking_links_active_expires_index ON tracking_links (active, expires_at, token)');
        }

        if (Schema::hasTable('push_subscriptions')) {
            DB::statement('CREATE INDEX IF NOT EXISTS push_subscriptions_active_user_index ON push_subscriptions (enabled, revoked_at, user_id)');
        }
    }

    public function down(): void
    {
        foreach ([
            'orders_created_at_desc_index',
            'orders_status_created_at_index',
            'orders_updated_at_desc_index',
            'orders_mottagare_adress_updated_index',
            'order_items_order_id_sort_index',
            'order_items_order_article_delivery_index',
            'order_items_work_order_article_delivery_index',
            'arbetsordrar_updated_at_desc_index',
            'arbetsorder_rader_order_sort_index',
            'arbetsorder_rader_order_article_index',
            'products_lower_sku_index',
            'products_sku_image_index',
            'purchases_created_at_desc_index',
            'purchases_status_created_at_index',
            'work_order_delivery_events_work_order_index',
            'tracking_links_active_expires_index',
            'push_subscriptions_active_user_index',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
