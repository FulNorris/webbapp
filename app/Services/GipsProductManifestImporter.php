<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GipsProductManifestImporter
{
    private const PATTERNS = [
        'R' => '/\b(?:RP\d+(?:-\d+)?|R\d+[A-Z]?(?:-\d+)?)\b/i',
        'TL' => '/\b(?:TLP\d+[A-Z]?|TLD\d+[A-Z]?|TL\d+[A-Z]?(?:-\d+)?)\b/i',
        'TLP' => '/\bTLP\d+[A-Z]?\b/i',
        'LS' => '/\b(?:SL|LS|LP|L)\d+[A-Z]?\b/i',
        'SLH' => '/\b(?:SLH|NLP)\d+[A-Z]?(?:-\d+)?\b/i',
        'D' => '/\bD\d+[A-Z]?\b/i',
        'DK' => '/\b(?:DK|ED|MC)\d+[A-Z]?(?:-\d+)?\b/i',
    ];

    public function import(string $manifest): array
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('product_images') || ! is_readable($manifest)) {
            return ['products' => 0, 'images' => 0, 'manifest' => $manifest];
        }

        $handle = fopen($manifest, 'r');
        if (! $handle) {
            return ['products' => 0, 'images' => 0, 'manifest' => $manifest];
        }

        $headers = fgetcsv($handle) ?: [];
        $products = [];
        $images = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, array_pad($row, count($headers), null));
            if (! $data) {
                continue;
            }

            if ($this->isRepeatedHeaderRow($data)) {
                continue;
            }

            $folder = trim((string) ($data['folder'] ?? ''));
            $sourceImage = trim((string) (($data['source_image'] ?? null) ?: ($data['source_image_url'] ?? '')));
            if ($this->isPlaceholderImage($sourceImage)) {
                continue;
            }

            $imagePath = trim((string) ($data['image_path'] ?? ''));
            $article = $this->canonicalArticle($data);
            if ($article === '' || $imagePath === '') {
                continue;
            }

            $imagePath = $this->canonicalImagePath($imagePath, $article, $folder, count($images[$article] ?? []) + 1);
            $title = trim((string) ($data['title'] ?? $article));
            $sourcePage = trim((string) (($data['source_page'] ?? null) ?: ($data['source_product_url'] ?? '')));
            $now = now();

            $products[$article] ??= [
                'sku' => $article,
                'folder' => $folder,
                'title' => $title ?: $article,
                'primary_image_path' => $imagePath,
                'primary_image_url' => $this->imageUrl($imagePath),
                'image_count' => 0,
            ];
            $products[$article]['image_count']++;

            $images[$article][] = [
                'product_sku' => $article,
                'image_index' => $products[$article]['image_count'],
                'folder' => $folder,
                'image_path' => $imagePath,
                'image_url' => $this->imageUrl($imagePath),
                'source_page' => $sourcePage ?: null,
                'source_image' => $sourceImage ?: null,
                'title' => $title ?: $article,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        fclose($handle);

        foreach ($products as $product) {
            $existing = DB::table('products')->where('sku', $product['sku'])->first();
            DB::table('products')->updateOrInsert(['sku' => $product['sku']], [
                'folder' => $product['folder'],
                'title' => $product['title'],
                'primary_image_path' => $product['primary_image_path'],
                'primary_image_url' => $product['primary_image_url'],
                'image_count' => $product['image_count'],
                'stock_total' => $existing->stock_total ?? 0,
                'stock_delivered' => $existing->stock_delivered ?? 0,
                'stock_available' => $existing->stock_available ?? ($existing->stock_total ?? 0),
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }

        $this->removeStaleProductRows(array_keys($products));

        $imageCount = 0;
        $currentImagePaths = [];
        foreach ($images as $productImages) {
            foreach ($productImages as $image) {
                DB::table('product_images')->updateOrInsert([
                    'product_sku' => $image['product_sku'],
                    'image_path' => $image['image_path'],
                ], $image);
                $currentImagePaths[] = $image['image_path'];
                $imageCount++;
            }
        }

        $currentImagePaths = array_values(array_unique($currentImagePaths));
        $imageCount = count($currentImagePaths);
        $this->removeStaleImageRows($currentImagePaths);
        $this->removePlaceholderImageRows();
        Cache::forget('dashboard:products');
        Cache::forget('dashboard:articles');

        return ['products' => count($products), 'images' => $imageCount, 'manifest' => $manifest];
    }

    public function canonicalArticle(array $data): string
    {
        $folder = trim((string) ($data['folder'] ?? ''));
        $pattern = self::PATTERNS[$folder] ?? '/\b(?:SLH|NLP|TLP|TLD|TL|SL|LS|LP|RP|R|DK|ED|MC|D)\d+[A-Z]?(?:-\d+)?\b/i';
        $manifestArticle = $this->cleanArticle((string) (($data['article_no'] ?? null) ?: ($data['sku'] ?? '')));
        if ($manifestArticle !== '') {
            return $manifestArticle;
        }

        $parts = [
            (string) ($data['title'] ?? ''),
            (string) (($data['source_product_url'] ?? null) ?: ($data['source_page'] ?? '')),
            (string) (($data['source_image_url'] ?? null) ?: ($data['source_image'] ?? '')),
        ];

        foreach ($parts as $part) {
            if (preg_match($pattern, $this->readableCodeText($part), $match)) {
                return $this->cleanArticle($match[0]);
            }
        }

        return '';
    }

    private function readableCodeText(string $text): string
    {
        $decoded = rawurldecode($text);
        $basename = pathinfo(parse_url($decoded, PHP_URL_PATH) ?: $decoded, PATHINFO_FILENAME);
        $combined = $decoded.' '.$basename;

        return str_replace(['_', '+'], '-', $combined);
    }

    private function cleanArticle(string $article): string
    {
        $article = Str::of($article)
            ->trim()
            ->replaceMatches('/[^A-Za-z0-9_-]+/', '')
            ->upper()
            ->toString();

        return str_replace('_', '-', $article);
    }

    private function canonicalImagePath(string $imagePath, string $article, string $folder, int $index): string
    {
        $path = str_replace('\\', '/', $imagePath);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg');
        if ($ext === 'jpeg') {
            $path = preg_replace('/\.jpeg$/i', '.jpg', $path) ?: $path;
        }

        return $path;
    }

    private function imageUrl(string $imagePath): ?string
    {
        $base = rtrim(str_replace('\\', '/', base_path('produkter')), '/').'/';
        $path = str_replace('\\', '/', $imagePath);
        if (! str_starts_with($path, $base)) {
            return null;
        }

        return '/produkter/'.implode('/', array_map('rawurlencode', explode('/', substr($path, strlen($base)))));
    }

    private function isPlaceholderImage(string $sourceImage): bool
    {
        $sourceImage = mb_strtolower($sourceImage, 'UTF-8');

        return str_contains($sourceImage, 'woocommerce-placeholder') || str_contains($sourceImage, 'placeholder');
    }

    private function isRepeatedHeaderRow(array $data): bool
    {
        $imagePath = mb_strtolower(trim((string) ($data['image_path'] ?? '')), 'UTF-8');
        $article = mb_strtolower(trim((string) (($data['article_no'] ?? null) ?: ($data['sku'] ?? ''))), 'UTF-8');

        return $imagePath === 'image_path' || in_array($article, ['article', 'article_no', 'sku'], true);
    }

    private function removePlaceholderImageRows(): void
    {
        DB::table('product_images')
            ->where(function ($query) {
                $query->whereRaw('lower(source_image) like ?', ['%placeholder%'])
                    ->orWhereRaw('lower(image_path) like ?', ['%placeholder%']);
            })
            ->delete();
    }

    private function removeStaleImageRows(array $currentImagePaths): void
    {
        $currentImagePaths = array_values(array_unique(array_filter($currentImagePaths)));
        if ($currentImagePaths === []) {
            DB::table('product_images')->delete();

            return;
        }

        DB::table('product_images')
            ->whereNotIn('image_path', $currentImagePaths)
            ->delete();
    }

    private function removeStaleProductRows(array $currentProductSkus): void
    {
        $currentProductSkus = array_values(array_unique(array_filter($currentProductSkus)));
        if ($currentProductSkus === []) {
            DB::table('products')->delete();

            return;
        }

        DB::table('products')
            ->whereNotIn('sku', $currentProductSkus)
            ->delete();
    }
}
