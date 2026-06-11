<?php

namespace App\Services\Purchases\Sources;

use Illuminate\Http\Client\Response;

class SwedolStockholmSource extends AbstractStockholmHtmlSource
{
    private const DEFAULT_ALGOLIA_APP_ID = 'IMDE5JWBQM';

    private const DEFAULT_ALGOLIA_INDEX = 'mcprod_swedol_sek_sv_products';

    private const DEFAULT_ALGOLIA_API_KEY = 'NmRhNThiOWZlZjY3ZTYzZDc0YTgzNmEwYjZkYzhjNzNjY2Q4NjIzMDllYmVjMTNkZWFmODMxZDA4NmU2OTNiYWZpbHRlcnM9aXNfcHJvY3VyZW1lbnQlMjAlMjElM0QlMjAxJnRhZ0ZpbHRlcnM9JnZhbGlkVW50aWw9MTc4MDYwNjUwMw==';

    protected string $key = 'swedol_stockholm';

    protected string $supplier = 'Swedol';

    protected string $store = 'Stockholm';

    protected string $baseUrl = 'https://www.swedol.se';

    protected string $mapsQuery = 'Swedol Stockholm';

    protected ?string $address = 'Swedol Stockholm';

    protected ?float $latitude = 59.3489;

    protected ?float $longitude = 18.0005;

    protected int $maxResults = 12;

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
                    'url',
                    'image_url',
                    'thumbnail_url',
                    'in_stock',
                    'in_stock_warehouse_ids',
                    'itembranddescription',
                    'short_description',
                    'sku',
                    'itemnumber',
                    'productnumber',
                    'itemgtinvalue',
                    'supplier_itemno',
                    'price',
                    'directshipment',
                    'assortment_sek',
                    'store_assortment',
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

        $products = [];
        foreach ($payload['hits'] as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $title = $this->titleFromHit($hit);
            $searchableText = trim($title.' '.$this->stringValue($hit['short_description'] ?? '').' '.$this->stringValue($hit['itembranddescription'] ?? ''));
            if ($title === '' || ! $this->matchesRequiredWords($query, $searchableText)) {
                continue;
            }

            $locations = $this->stockLocationsFromHit($hit);
            $result = $this->result(
                query: $query,
                title: $title,
                sku: $this->firstStringValue($hit, ['sku', 'itemnumber', 'productnumber', 'supplier_itemno']),
                price: $this->priceFromHit($hit),
                currency: 'SEK',
                availability: $this->availabilityFromHit($hit, $locations),
                productUrl: $this->absoluteUrl($this->stringValue($hit['url'] ?? '')),
                imageUrl: $this->absoluteUrl($this->stringValue($hit['image_url'] ?? $hit['thumbnail_url'] ?? '')),
                fetchedAt: $fetchedAt,
                stockQuantity: null,
            );

            $products[] = $this->withStockLocations($result, $locations);
        }

        return array_slice($products, 0, $this->maxResults);
    }

    private function algoliaAppId(): string
    {
        $value = config('services.purchase_suppliers.swedol_stockholm.algolia_app_id');

        return is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT_ALGOLIA_APP_ID;
    }

    private function algoliaIndex(): string
    {
        $value = config('services.purchase_suppliers.swedol_stockholm.algolia_index');

        return is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT_ALGOLIA_INDEX;
    }

    private function algoliaApiKey(): string
    {
        $value = config('services.purchase_suppliers.swedol_stockholm.algolia_api_key');

        return is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT_ALGOLIA_API_KEY;
    }

    private function titleFromHit(array $hit): string
    {
        $name = $this->stringValue($hit['name'] ?? '');
        $brand = $this->stringValue($hit['itembranddescription'] ?? '');

        if ($name === '') {
            return '';
        }

        if ($brand !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($brand))) {
            return trim($brand.' '.$name);
        }

        return $name;
    }

    private function priceFromHit(array $hit): ?float
    {
        $sek = $hit['price']['SEK'] ?? null;
        if (! is_array($sek)) {
            return null;
        }

        foreach (['default', 'special', 'value'] as $key) {
            if (isset($sek[$key]) && is_numeric($sek[$key])) {
                return round((float) $sek[$key], 2);
            }
        }

        foreach ($sek as $key => $value) {
            if (str_contains((string) $key, 'formated')) {
                continue;
            }

            if (is_numeric($value)) {
                return round((float) $value, 2);
            }
        }

        return null;
    }

    private function availabilityFromHit(array $hit, array $locations): string
    {
        $inStock = $hit['in_stock'] ?? null;
        if (is_bool($inStock)) {
            return $inStock ? 'I lager' : 'Ej i lager';
        }

        if (is_numeric($inStock)) {
            return ((int) $inStock) > 0 ? 'I lager' : 'Ej i lager';
        }

        return $locations !== [] ? 'I lager' : "Kontrollera lagerstatus i {$this->store}";
    }

    private function stockLocationsFromHit(array $hit): array
    {
        $locations = [];
        $warehouseIds = $hit['in_stock_warehouse_ids'] ?? [];
        if (! is_array($warehouseIds)) {
            return [];
        }

        foreach ($warehouseIds as $location) {
            if (! is_string($location) && ! is_numeric($location)) {
                continue;
            }

            $name = trim((string) $location);
            if ($name !== '' && ! in_array($name, $locations, true)) {
                $locations[] = $name;
            }
        }

        return array_slice($locations, 0, 8);
    }

    private function withStockLocations(array $result, array $locations): array
    {
        if ($locations === []) {
            return $result;
        }

        $result['stockLocations'] = $locations;
        $result['stockText'] = 'I lager: '.implode(', ', $locations);

        return $result;
    }

    private function firstStringValue(array $hit, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->stringValue($hit[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
}
