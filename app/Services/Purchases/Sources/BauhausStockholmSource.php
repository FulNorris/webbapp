<?php

namespace App\Services\Purchases\Sources;

use Illuminate\Http\Client\Response;

class BauhausStockholmSource extends AbstractStockholmHtmlSource
{
    private const DEFAULT_ALGOLIA_APP_ID = 'TGPIEONN2S';

    private const DEFAULT_ALGOLIA_INDEX = 'nordic_production_sv_products';

    private const DEFAULT_ALGOLIA_API_KEY = 'NTUwMmEzMjIzZWEwY2ZiOTliNjkwNDA4NGM5Yjc1MWM3NDc0NGIwZTQ4YjNjZDBiOTZlZGFiZTU0OWRjMjk5MnRhZ0ZpbHRlcnM9JnZhbGlkVW50aWw9MTc4MDYwNjczMg==';

    protected string $key = 'bauhaus_stockholm';

    protected string $supplier = 'Bauhaus';

    protected string $store = 'Bromma';

    protected string $baseUrl = 'https://www.bauhaus.se';

    protected string $mapsQuery = 'Bauhaus Bromma';

    protected ?string $address = 'Bauhaus Bromma, Ulvsundavägen 108, Bromma';

    protected ?float $latitude = 59.3566;

    protected ?float $longitude = 17.9566;

    protected function buildSearchUrl(string $query): string
    {
        return $this->baseUrl.'/catalogsearch/result/?q='.rawurlencode($query);
    }

    public function searchRequest(string $query): array
    {
        $appId = $this->algoliaAppId();
        $index = $this->algoliaIndex();

        return [
            'method' => 'post',
            'url' => "https://{$appId}-dsn.algolia.net/1/indexes/{$index}/query",
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Language' => 'sv-SE,sv;q=0.9,en;q=0.5',
                'Content-Type' => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
                'X-Algolia-Application-Id' => $appId,
                'X-Algolia-API-Key' => $this->algoliaApiKey(),
            ],
            'json' => [
                'query' => $query,
                'hitsPerPage' => $this->maxResults,
                'attributesToRetrieve' => [
                    'name',
                    'sku',
                    'url',
                    'image_url',
                    'price',
                    'availability',
                    'stock',
                    'stock_qty',
                    'stock_quantity',
                    'quantity',
                    'inventory',
                    'inventoryLevel',
                    'store_stock',
                    'stores',
                    'brand',
                ],
            ],
        ];
    }

    protected function sourceMode(): string
    {
        return 'public_search_index';
    }

    public function normalizeResponse(string $query, Response $response, \DateTimeInterface $fetchedAt): array
    {
        $payload = $response->json();
        if (! is_array($payload) || ! is_array($payload['hits'] ?? null)) {
            return parent::normalizeResponse($query, $response, $fetchedAt);
        }

        $hits = $payload['hits'];
        $products = [];

        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $title = $this->stringValue($hit['name'] ?? '');
            if ($title === '' || ! $this->matchesRequiredWords($query, $title)) {
                continue;
            }

            $products[] = $this->result(
                query: $query,
                title: $title,
                sku: $this->stringValue($hit['sku'] ?? ''),
                price: $this->priceFromAlgoliaHit($hit),
                currency: 'SEK',
                availability: $this->availabilityFromAlgoliaHit($hit),
                productUrl: $this->absoluteUrl($this->stringValue($hit['url'] ?? '')),
                imageUrl: $this->absoluteUrl($this->stringValue($hit['image_url'] ?? '')),
                fetchedAt: $fetchedAt,
                stockQuantity: $this->stockQuantityFromAlgoliaHit($hit),
            );
        }

        return array_slice($products, 0, $this->maxResults);
    }

    private function algoliaAppId(): string
    {
        $value = config('services.purchase_suppliers.bauhaus_stockholm.algolia_app_id');

        return is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT_ALGOLIA_APP_ID;
    }

    private function algoliaIndex(): string
    {
        $value = config('services.purchase_suppliers.bauhaus_stockholm.algolia_index');

        return is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT_ALGOLIA_INDEX;
    }

    private function algoliaApiKey(): string
    {
        $value = config('services.purchase_suppliers.bauhaus_stockholm.algolia_api_key');

        return is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT_ALGOLIA_API_KEY;
    }

    private function priceFromAlgoliaHit(array $hit): ?float
    {
        $sek = $hit['price']['SEK'] ?? null;
        if (! is_array($sek)) {
            return null;
        }

        foreach (['group_0', 'default', 'value'] as $key) {
            if (isset($sek[$key]) && is_numeric($sek[$key])) {
                return round((float) $sek[$key], 2);
            }
        }

        foreach ($sek as $key => $value) {
            if (str_ends_with((string) $key, '_default_formatted')) {
                continue;
            }

            if (is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return null;
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            foreach ($value as $candidate) {
                $normalized = $this->stringValue($candidate);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
    }

    private function availabilityFromAlgoliaHit(array $hit): string
    {
        $availability = $hit['availability'] ?? null;
        if (is_numeric($availability)) {
            return ((int) $availability) > 0 ? 'I lager' : 'Ej i lager';
        }

        if (is_bool($availability)) {
            return $availability ? 'I lager' : 'Ej i lager';
        }

        return "Kontrollera lagerstatus i {$this->store}";
    }

    private function stockQuantityFromAlgoliaHit(array $hit): ?int
    {
        foreach ([
            'stock',
            'stock_qty',
            'stock_quantity',
            'quantity',
            'available_quantity',
            'inventory',
            'inventoryLevel',
            'availability',
        ] as $key) {
            if (! array_key_exists($key, $hit)) {
                continue;
            }

            $quantity = $this->firstNumericStockValue($hit[$key]);
            if ($quantity !== null) {
                return $quantity;
            }
        }

        return $this->firstNumericStockValue($hit);
    }

    private function firstNumericStockValue(mixed $value): ?int
    {
        if (is_bool($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $key => $candidate) {
            $keyText = mb_strtolower((string) $key, 'UTF-8');
            $looksLikeStockKey = preg_match('/stock|lager|saldo|qty|quantity|available|inventory|bromma/u', $keyText);
            if ($looksLikeStockKey && is_numeric($candidate)) {
                return max(0, (int) $candidate);
            }

            if (is_array($candidate)) {
                $nested = $this->firstNumericStockValue($candidate);
                if ($looksLikeStockKey && $nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
