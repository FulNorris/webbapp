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

        $products = DB::table('products')->get();
        $bySku = $products->keyBy(fn ($product) => mb_strtolower($product->sku, 'UTF-8'));
        $byTitle = $products->filter(fn ($product) => $product->title)->keyBy(fn ($product) => mb_strtolower($product->title, 'UTF-8'));

        DB::table('order_items')
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($bySku, $byTitle) {
                foreach ($items as $item) {
                    $article = trim((string) $item->artikel);
                    $key = mb_strtolower($article, 'UTF-8');
                    $firstToken = mb_strtolower(strtok($article, " \t\r\n-") ?: $article, 'UTF-8');
                    $product = $bySku->get($key) ?: $bySku->get($firstToken) ?: $byTitle->get($key);
                    $requested = $this->quantityValue($item->antal);
                    $delivered = $this->quantityValue($item->delivered_antal ?? 0);

                    DB::table('order_items')->where('id', $item->id)->update([
                        'product_sku' => $product?->sku,
                        'product_title' => $product?->title,
                        'product_image_path' => $product?->primary_image_path,
                        'requested_quantity' => $requested,
                        'delivered_quantity' => $delivered,
                        'remaining_quantity' => max(0, $requested - $delivered),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Backfill is intentionally not reversed.
    }

    private function quantityValue($value): int
    {
        if (preg_match('/\d+(?:[,.]\d+)?/', (string) $value, $matches)) {
            return max(0, (int) ceil((float) str_replace(',', '.', $matches[0])));
        }

        return 0;
    }
};
