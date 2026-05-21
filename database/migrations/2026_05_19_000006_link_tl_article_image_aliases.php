<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('order_items')) {
            return;
        }

        $products = DB::table('products')->get();

        DB::table('order_items')
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($products) {
                foreach ($items as $item) {
                    $article = trim((string) (($item->product_sku ?? null) ?: $item->artikel));
                    $product = $this->resolveProduct($products, $article);

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

    private function resolveProduct(Collection $products, string $article): ?object
    {
        $fallback = null;

        foreach ($this->productSkuCandidates($article) as $candidate) {
            $product = $products->first(fn ($row) => mb_strtolower($row->sku, 'UTF-8') === $candidate);
            if (! $product) {
                continue;
            }

            if ($this->productHasImage($product)) {
                return $product;
            }

            $fallback ??= $product;
        }

        foreach ($this->productSkuCandidates($article) as $candidate) {
            if (mb_strlen($candidate, 'UTF-8') < 3) {
                continue;
            }

            $product = $products->first(fn ($row) => $this->productHasImage($row)
                && str_contains(mb_strtolower((string) $row->title, 'UTF-8'), $candidate));

            if ($product) {
                return $product;
            }
        }

        return $fallback;
    }

    private function productSkuCandidates(string $article): array
    {
        $key = mb_strtolower(trim($article), 'UTF-8');
        $firstToken = mb_strtolower(strtok($article, " \t\r\n-") ?: $article, 'UTF-8');
        $compact = preg_replace('/[^a-z0-9]/', '', $key) ?: '';
        $candidates = [$key, $firstToken, $compact];

        if (preg_match('/^tlp?(\d+)/', $compact, $matches)) {
            $digits = $matches[1];
            $listNumber = substr($digits, 0, 2);
            array_push($candidates, 'sl'.$digits, 'sl'.$listNumber, 'tl'.$digits);

            if (str_starts_with($compact, 'tlp')) {
                array_push($candidates, 'tlp'.$digits, 'rp7-'.$digits.'0', 'rp7'.$digits.'0');
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    private function productHasImage(object $product): bool
    {
        return trim((string) ($product->primary_image_path ?? '')) !== ''
            || trim((string) ($product->primary_image_url ?? '')) !== '';
    }
};
