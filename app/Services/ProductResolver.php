<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductResolver
{
    private array $memoryCache = [];
    private ?bool $productsTableExists = null;
    private ?string $catalogVersion = null;

    public function forArticle(?string $article): ?object
    {
        $article = trim((string) $article);
        if ($article === '' || ! $this->productsTableExists()) {
            return null;
        }

        $cacheKey = 'product_resolver:'.$this->catalogVersion().':'.sha1(mb_strtolower($article, 'UTF-8'));
        if (array_key_exists($cacheKey, $this->memoryCache)) {
            return $this->memoryCache[$cacheKey];
        }

        $product = app()->environment('testing')
            ? $this->resolveArticle($article)
            : Cache::remember($cacheKey, 3600, fn () => $this->resolveArticle($article));
        $this->memoryCache[$cacheKey] = $product;

        return $product;
    }

    private function resolveArticle(string $article): ?object
    {
        $article = trim($article);
        if ($article === '') {
            return null;
        }

        $candidates = $this->skuCandidates($article);
        foreach ($candidates as $candidate) {
            $titleProduct = $this->findVisibleProductByTitle($candidate);
            if ($titleProduct) {
                return $titleProduct;
            }

            $exactVisibleProduct = $this->findBySku($candidate, true);
            if ($exactVisibleProduct) {
                return $exactVisibleProduct;
            }
        }

        foreach ($candidates as $candidate) {
            if ($product = $this->findBySku($candidate, true)) {
                return $product;
            }
        }

        foreach ($candidates as $candidate) {
            if ($product = $this->findBySku($candidate, true)) {
                return $product;
            }
        }

        foreach ($candidates as $candidate) {
            if ($product = $this->findBySku($candidate, false)) {
                return $product;
            }
        }

        return null;
    }

    public function skuCandidates(string $article): array
    {
        $raw = trim($article);
        $normalized = mb_strtolower(ArbetsorderParser::normalizeArticle($raw), 'UTF-8');
        $compact = preg_replace('/[^a-z0-9]/', '', mb_strtolower($raw, 'UTF-8')) ?: '';
        $firstToken = mb_strtolower(strtok($raw, " \t\r\n") ?: $raw, 'UTF-8');
        $candidates = [$normalized, $compact, preg_replace('/[^a-z0-9]/', '', $firstToken) ?: $firstToken];

        if (preg_match_all('/\b(SLH|NLP|TLP|TLD|TL|SL|LS|LP|RP|R|DK|ED|MC|D)\s*-?\s*(\d+[A-Z]?(?:-\d+)?)\b/iu', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $code = mb_strtolower($match[1].$match[2], 'UTF-8');
                $candidates[] = $code;
                $candidates[] = str_replace('-', '', $code);
            }
        }

        if (str_starts_with($normalized, 'nlp')) {
            $candidates[] = 'slh'.substr($normalized, 3);
        }

        if (str_starts_with($normalized, 'slh')) {
            $candidates[] = 'nlp'.substr($normalized, 3);
        }

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

    private function findBySku(string $candidate, bool $requireImage): ?object
    {
        if (mb_strlen($candidate, 'UTF-8') < 2) {
            return null;
        }

        return DB::table('products')
            ->whereRaw('lower(sku) = ?', [mb_strtolower($candidate, 'UTF-8')])
            ->when($requireImage, function ($query) {
                $query->where(function ($imageQuery) {
                    $imageQuery->whereNotNull('primary_image_path')
                        ->orWhereNotNull('primary_image_url');
                });
            })
            ->first();
    }

    private function findVisibleProductByTitle(string $candidate): ?object
    {
        if (mb_strlen($candidate, 'UTF-8') < 3) {
            return null;
        }

        return DB::table('products')
            ->whereRaw('lower(title) like ?', ['%'.mb_strtolower($candidate, 'UTF-8').'%'])
            ->whereRaw('lower(sku) <> ?', [mb_strtolower($candidate, 'UTF-8')])
            ->where(function ($query) {
                $query->whereNotNull('primary_image_path')
                    ->orWhereNotNull('primary_image_url');
            })
            ->orderBy('sku')
            ->first();
    }

    private function productsTableExists(): bool
    {
        return $this->productsTableExists ??= Schema::hasTable('products');
    }

    private function catalogVersion(): string
    {
        if ($this->catalogVersion !== null) {
            return $this->catalogVersion;
        }

        try {
            $connection = DB::connection();
            $database = (string) $connection->getDatabaseName();
            $searchPath = (string) ($connection->getConfig('search_path') ?: 'public');
            $updatedAt = DB::table('products')->max('updated_at') ?: 'empty';
            $count = DB::table('products')->count();

            return $this->catalogVersion = sha1($database.'|'.$searchPath.'|'.$count.'|'.$updatedAt);
        } catch (\Throwable) {
            return $this->catalogVersion = 'unknown';
        }
    }
}
