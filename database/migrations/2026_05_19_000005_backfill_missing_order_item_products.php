<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('order_items')) {
            return;
        }

        $items = DB::table('order_items')
            ->select('artikel', DB::raw('sum(coalesce(requested_quantity, 0)) as requested_total'))
            ->whereNotNull('artikel')
            ->where('artikel', '<>', '')
            ->groupBy('artikel')
            ->get();

        foreach ($items as $item) {
            $sku = trim((string) $item->artikel);
            if ($sku === '' || DB::table('products')->whereRaw('lower(sku) = ?', [mb_strtolower($sku, 'UTF-8')])->exists()) {
                continue;
            }

            DB::table('products')->insert([
                'sku' => $sku,
                'folder' => 'manual',
                'title' => $sku,
                'primary_image_path' => null,
                'primary_image_url' => null,
                'image_count' => 0,
                'stock_total' => max(0, (int) $item->requested_total),
                'stock_delivered' => 0,
                'stock_available' => max(0, (int) $item->requested_total),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('order_items')
            ->whereNull('product_sku')
            ->orderBy('id')
            ->chunkById(200, function ($items) {
                foreach ($items as $item) {
                    $product = DB::table('products')
                        ->whereRaw('lower(sku) = ?', [mb_strtolower(trim((string) $item->artikel), 'UTF-8')])
                        ->first();
                    if (! $product) {
                        continue;
                    }

                    DB::table('order_items')->where('id', $item->id)->update([
                        'product_sku' => $product->sku,
                        'product_title' => $product->title,
                        'product_image_path' => $product->primary_image_path,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Manual product backfill is intentionally retained.
    }
};
