<?php

use App\Services\ProductResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('order_items')) {
            return;
        }

        $resolver = app(ProductResolver::class);

        DB::table('order_items')
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($resolver) {
                foreach ($items as $item) {
                    $article = trim((string) (($item->product_sku ?? null) ?: $item->artikel));
                    $product = $resolver->forArticle($article);

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
        // Alias backfill is intentionally retained.
    }

};
