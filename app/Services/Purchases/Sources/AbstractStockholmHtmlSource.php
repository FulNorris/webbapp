<?php

namespace App\Services\Purchases\Sources;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

abstract class AbstractStockholmHtmlSource implements ProductSearchSourceInterface
{
    protected string $key;

    protected string $supplier;

    protected string $store;

    protected string $city = 'Stockholm';

    protected string $baseUrl;

    protected string $mapsQuery;

    protected ?string $address = null;

    protected ?float $latitude = null;

    protected ?float $longitude = null;

    protected bool $active = true;

    protected int $maxResults = 12;

    abstract protected function buildSearchUrl(string $query): string;

    public function __construct()
    {
        $configuredStore = config("services.purchase_suppliers.{$this->key}.stock_location");
        if (is_string($configuredStore) && trim($configuredStore) !== '') {
            $this->store = trim($configuredStore);
            $this->mapsQuery = "{$this->supplier} {$this->store}";
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function supplier(): string
    {
        return $this->supplier;
    }

    public function store(): string
    {
        return $this->store;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function searchUrl(string $query): string
    {
        return $this->buildSearchUrl($query);
    }

    public function searchRequest(string $query): array
    {
        return [
            'method' => 'get',
            'url' => $this->searchUrl($query),
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/json',
                'Accept-Language' => 'sv-SE,sv;q=0.9,en;q=0.5',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
            ],
        ];
    }

    public function mapsLabel(): string
    {
        return "Kör till {$this->supplier} {$this->store}";
    }

    public function mapsUrl(): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->address ?: $this->mapsQuery);
    }

    public function normalizeResponse(string $query, Response $response, \DateTimeInterface $fetchedAt): array
    {
        $html = (string) $response->body();
        if ($html === '') {
            return [];
        }

        $results = array_merge(
            $this->productsFromJsonLd($query, $html, $fetchedAt),
            $this->productsFromHtmlCards($query, $html, $fetchedAt),
        );

        $unique = [];
        foreach ($results as $result) {
            if (! $this->isAllowedStoreResult($result)) {
                continue;
            }

            $dedupeKey = mb_strtolower(($result['sku'] ?: '').'|'.$result['title'].'|'.$result['productUrl']);
            if (isset($unique[$dedupeKey])) {
                continue;
            }

            $unique[$dedupeKey] = $result;
        }

        return array_slice(array_values($unique), 0, $this->maxResults);
    }

    protected function productsFromJsonLd(string $query, string $html, \DateTimeInterface $fetchedAt): array
    {
        $products = [];
        if (! preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            return [];
        }

        foreach ($matches[1] as $json) {
            $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($this->extractJsonLdProducts($decoded) as $product) {
                $normalized = $this->normalizeJsonLdProduct($query, $product, $fetchedAt);
                if ($normalized !== null) {
                    $products[] = $normalized;
                }
            }
        }

        return $products;
    }

    protected function extractJsonLdProducts(array $node): array
    {
        $products = [];
        $type = $node['@type'] ?? null;
        if (is_string($type) && strcasecmp($type, 'Product') === 0) {
            $products[] = $node;
        }

        foreach (['@graph', 'itemListElement', 'items'] as $key) {
            if (! isset($node[$key]) || ! is_array($node[$key])) {
                continue;
            }

            foreach ($node[$key] as $child) {
                if (is_array($child)) {
                    $products = array_merge($products, $this->extractJsonLdProducts($child['item'] ?? $child));
                }
            }
        }

        return $products;
    }

    protected function normalizeJsonLdProduct(string $query, array $product, \DateTimeInterface $fetchedAt): ?array
    {
        $title = trim((string) ($product['name'] ?? ''));
        if ($title === '') {
            return null;
        }

        $offers = $product['offers'] ?? [];
        if (isset($offers[0]) && is_array($offers[0])) {
            $offers = $offers[0];
        }

        $price = $this->normalizePrice($offers['price'] ?? $offers['lowPrice'] ?? null);
        $currency = strtoupper((string) ($offers['priceCurrency'] ?? 'SEK')) ?: 'SEK';
        $availability = $this->availabilityText((string) ($offers['availability'] ?? ''));

        return $this->result(
            query: $query,
            title: $title,
            sku: (string) ($product['sku'] ?? $product['mpn'] ?? ''),
            price: $price,
            currency: $currency,
            availability: $availability,
            productUrl: $this->absoluteUrl((string) ($product['url'] ?? $offers['url'] ?? '')),
            imageUrl: $this->absoluteUrl(is_array($product['image'] ?? null) ? (string) ($product['image'][0] ?? '') : (string) ($product['image'] ?? '')),
            fetchedAt: $fetchedAt,
            stockQuantity: $this->stockQuantityFromJsonLd($product, $offers),
        );
    }

    protected function productsFromHtmlCards(string $query, string $html, \DateTimeInterface $fetchedAt): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//a[@href]');
        if (! $nodes) {
            return [];
        }

        $products = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $text = $this->cleanText($node->textContent);
            if ($text === '' || mb_strlen($text) < 4 || ! $this->looksLikeProductText($query, $text)) {
                continue;
            }

            $href = (string) $node->getAttribute('href');
            if (! $this->looksLikeProductUrl($href)) {
                continue;
            }

            $container = $this->nearestProductContainer($node);
            $containerText = $this->cleanText($container?->textContent ?: $text);
            $title = $this->cleanTitle($text);
            $price = $this->priceFromText($text) ?? $this->priceFromText($containerText);
            if ($price === null && $this->matchScore($query, $title) < 0.72) {
                continue;
            }

            $availability = $this->availabilityText($containerText);
            $stockQuantity = $this->stockQuantityFromText($containerText);
            $sku = $this->firstMatch('/(?:art(?:ikel)?\.?\s*nr|artikelnr|sku|varunr)\s*[:#]?\s*([A-ZÅÄÖ0-9._-]{3,})/iu', $containerText) ?: '';
            $imageUrl = $this->firstImageUrl($container);

            $products[] = $this->result(
                query: $query,
                title: $title,
                sku: $sku,
                price: $price,
                currency: 'SEK',
                availability: $availability,
                productUrl: $this->absoluteUrl($href),
                imageUrl: $this->absoluteUrl($imageUrl),
                fetchedAt: $fetchedAt,
                stockQuantity: $stockQuantity,
            );

            if (count($products) >= $this->maxResults) {
                break;
            }
        }

        return $products;
    }

    protected function result(
        string $query,
        string $title,
        string $sku,
        ?float $price,
        string $currency,
        string $availability,
        string $productUrl,
        string $imageUrl,
        \DateTimeInterface $fetchedAt,
        ?int $stockQuantity = null,
        string $stockUnit = 'st',
    ): array {
        if ($availability === 'Okänd lagerstatus') {
            $availability = "Kontrollera lagerstatus i {$this->store}";
        }

        $rank = $this->availabilityRank($availability);
        $stockText = $this->stockText($availability, $stockQuantity, $stockUnit);

        return [
            'supplier' => $this->supplier,
            'store' => $this->store,
            'city' => $this->city,
            'title' => $title,
            'sku' => $sku !== '' ? $sku : null,
            'price' => $price,
            'currency' => $currency !== '' ? $currency : 'SEK',
            'availability' => $availability,
            'availabilityRank' => $rank,
            'stockQuantity' => $stockQuantity,
            'stockUnit' => $stockQuantity !== null ? $stockUnit : null,
            'stockText' => $stockText,
            'stockLocations' => $stockQuantity !== null ? ["{$this->supplier} {$this->store}"] : [],
            'productUrl' => $productUrl !== '' ? $productUrl : null,
            'imageUrl' => $imageUrl !== '' ? $imageUrl : null,
            'mapsLabel' => $this->mapsLabel(),
            'mapsUrl' => $this->mapsUrl(),
            'storeLatitude' => $this->latitude,
            'storeLongitude' => $this->longitude,
            'matchScore' => $this->matchScore($query, $title),
            'dataSource' => $this->sourceMode(),
            'stockStatusScope' => $this->stockStatusScope(),
            'officialApiConfigured' => $this->officialApiKeyConfigured(),
            'fetchedAt' => $fetchedAt->format(DATE_ATOM),
        ];
    }

    protected function sourceMode(): string
    {
        return $this->hasOfficialApiCredentials() ? 'official_api' : 'public_search';
    }

    protected function stockStatusScope(): string
    {
        return $this->hasOfficialApiCredentials() ? 'store' : 'supplier';
    }

    protected function hasOfficialApiCredentials(): bool
    {
        if (! $this->supportsOfficialApi()) {
            return false;
        }

        return $this->officialApiKeyConfigured();
    }

    protected function officialApiKeyConfigured(): bool
    {
        $apiKey = config("services.purchase_suppliers.{$this->key}.api_key");

        return is_string($apiKey) && trim($apiKey) !== '';
    }

    protected function supportsOfficialApi(): bool
    {
        return false;
    }

    protected function isAllowedStoreResult(array $result): bool
    {
        return $result['supplier'] === $this->supplier
            && $result['store'] === $this->store
            && $result['city'] === 'Stockholm';
    }

    protected function looksLikeProductText(string $query, string $text): bool
    {
        $title = $this->cleanTitle($text);
        if (! $this->matchesRequiredWords($query, $title)) {
            return false;
        }

        return $this->matchScore($query, $title) >= $this->minimumMatchScore($query);
    }

    protected function looksLikeProductUrl(string $url): bool
    {
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'javascript:')) {
            return false;
        }

        if (preg_match('/(kundservice|kontakt|login|varuhus|butik|checkout|cart|kassa|konto|club|kundklubb|faktura|order|retur|inspiration|foretag|företag|hallbarhet|hållbarhet|language|sprak|språk|cafe|café)/i', $url)) {
            return false;
        }

        return (bool) preg_match('/(\d{3,}|catalog|produkt|product|p-|\/p\/)/i', $url);
    }

    protected function nearestProductContainer(DOMElement $node): ?DOMElement
    {
        $current = $node;
        for ($depth = 0; $depth < 5; $depth++) {
            if (! $current->parentNode instanceof DOMElement) {
                return $current;
            }

            $current = $current->parentNode;
            $class = (string) $current->getAttribute('class');
            if (preg_match('/product|item|card|tile|article|hit|search/i', $class)) {
                return $current;
            }
        }

        return $current;
    }

    protected function firstImageUrl(?DOMElement $container): string
    {
        if (! $container) {
            return '';
        }

        foreach ($container->getElementsByTagName('img') as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            foreach (['src', 'data-src', 'data-lazy-src', 'data-original'] as $attribute) {
                $value = trim((string) $image->getAttribute($attribute));
                if ($value !== '' && ! str_starts_with($value, 'data:')) {
                    return $value;
                }
            }
        }

        return '';
    }

    protected function cleanTitle(string $text): string
    {
        $text = preg_replace('/^(?:bästsäljare|prisad produkt|nyhet|kampanj|rea)!?/iu', '', $text) ?: $text;
        $text = preg_replace('/\|.*$/u', '', $text) ?: $text;
        $text = preg_replace('/\d[\d\s.,]*(?:kr|sek|:-|\.-).*$/iu', '', $text) ?: $text;

        return Str::of($text)->squish()->limit(120, '')->toString();
    }

    protected function cleanText(string $text): string
    {
        return Str::of(html_entity_decode($text, ENT_QUOTES | ENT_HTML5))->replaceMatches('/\s+/u', ' ')->trim()->toString();
    }

    protected function firstMatch(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $match) ? trim((string) ($match[1] ?? '')) : null;
    }

    protected function normalizePrice(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = preg_replace('/[^\d,.\-]/', '', (string) $value) ?: '';
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
        }

        $normalized = str_replace(',', '.', $normalized);
        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    protected function priceFromText(string $text): ?float
    {
        if (! preg_match_all('/(?<![\d,.])(\d{1,6}(?:[,.]\d{1,2})?)\s*(?:kr|sek|:-|\.-)/iu', $text, $matches)) {
            return null;
        }

        foreach (array_reverse($matches[1]) as $candidate) {
            $price = $this->normalizePrice($candidate);
            if ($price !== null && $price > 0) {
                return $price;
            }
        }

        return null;
    }

    protected function availabilityText(string $text): string
    {
        $lower = mb_strtolower($text);
        if (str_contains($lower, 'schema.org/outofstock') || str_contains($lower, 'ej i lager') || str_contains($lower, 'slut i lager') || str_contains($lower, 'inte i lager')) {
            return 'Ej i lager';
        }

        if (str_contains($lower, 'få i lager') || str_contains($lower, 'begränsat') || str_contains($lower, 'lågt lager')) {
            return 'Få i lager';
        }

        if (str_contains($lower, 'instock') || str_contains($lower, 'i lager') || str_contains($lower, 'finns i lager')) {
            return 'I lager';
        }

        if (str_contains($lower, 'beställningsvara') || str_contains($lower, 'bestallningsvara') || str_contains($lower, 'online only')) {
            return 'Beställningsvara';
        }

        return 'Okänd lagerstatus';
    }

    protected function availabilityRank(string $availability): int
    {
        $lower = mb_strtolower($availability);

        return match (true) {
            str_contains($lower, 'få') => 2,
            str_contains($lower, 'i lager') && ! str_contains($lower, 'ej') => 1,
            str_contains($lower, 'beställ') || str_contains($lower, 'bestall') => 3,
            str_contains($lower, 'ej') || str_contains($lower, 'slut') => 4,
            str_contains($lower, 'kontrollera') => 5,
            default => 5,
        };
    }

    protected function stockText(string $availability, ?int $stockQuantity, string $stockUnit = 'st'): string
    {
        if ($stockQuantity !== null) {
            return "{$stockQuantity} {$stockUnit} i {$this->supplier} {$this->store}";
        }

        $lower = mb_strtolower($availability, 'UTF-8');
        if (str_contains($lower, 'i lager') && ! str_contains($lower, 'ej')) {
            return "Antal ej angivet för {$this->supplier} {$this->store}";
        }

        return '';
    }

    protected function stockQuantityFromJsonLd(array $product, array $offers): ?int
    {
        foreach ([
            $offers['inventoryLevel']['value'] ?? null,
            $offers['inventoryLevel'] ?? null,
            $offers['quantity'] ?? null,
            $offers['availableQuantity'] ?? null,
            $product['inventoryLevel']['value'] ?? null,
            $product['inventoryLevel'] ?? null,
            $product['quantity'] ?? null,
            $product['availableQuantity'] ?? null,
        ] as $value) {
            if (is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }

    protected function stockQuantityFromText(string $text): ?int
    {
        $patterns = [
            '/(?:lager|lagersaldo|saldo|kvar|tillgänglig(?:t|a)?|tillganglig(?:t|a)?|finns)\D{0,45}(\d{1,5})\s*(?:st|stycken|pcs|artiklar)?/iu',
            '/(\d{1,5})\s*(?:st|stycken|pcs)\D{0,35}(?:i lager|kvar|tillgänglig(?:t|a)?|tillganglig(?:t|a)?)/iu',
            '/(?:i lager)\D{0,35}(\d{1,5})\s*(?:st|stycken|pcs)?/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return max(0, (int) $match[1]);
            }
        }

        return null;
    }

    protected function matchScore(string $query, string $title): float
    {
        $queryKey = $this->searchKey($query);
        $titleKey = $this->searchKey($title);
        if ($queryKey === '' || $titleKey === '') {
            return 0.0;
        }

        $queryWords = $this->searchWords($queryKey);
        $hits = 0;
        foreach ($queryWords as $word) {
            if (mb_strlen($word) >= 2 && str_contains($titleKey, $word)) {
                $hits++;
            }
        }

        similar_text($queryKey, $titleKey, $percent);
        $wordScore = count($queryWords) > 0 ? $hits / count($queryWords) : 0;

        return round(min(1, max($wordScore, $percent / 100)), 4);
    }

    protected function minimumMatchScore(string $query): float
    {
        $words = $this->searchWords($this->searchKey($query));
        $count = count($words);

        return match (true) {
            $count >= 4 => 0.42,
            $count === 3 => 0.42,
            $count === 2 => 0.42,
            default => 0.34,
        };
    }

    protected function matchesRequiredWords(string $query, string $title): bool
    {
        $titleKey = $this->searchKey($title);
        $requiredWords = array_values(array_filter(
            $this->searchWords($this->searchKey($query)),
            fn (string $word) => ! $this->isNonProductQualifier($word)
        ));

        if ($requiredWords === []) {
            return true;
        }

        if ($this->hasContradictingPhrase($titleKey, $requiredWords)) {
            return false;
        }

        foreach ($requiredWords as $word) {
            if (! str_contains($titleKey, $word)) {
                return false;
            }
        }

        return true;
    }

    protected function hasContradictingPhrase(string $titleKey, array $requiredWords): bool
    {
        if (in_array('batteri', $requiredWords, true) || in_array('batterier', $requiredWords, true)) {
            $searchesForAdapter = in_array('adapter', $requiredWords, true)
                || in_array('adapt', $requiredWords, true)
                || in_array('batteriadapter', $requiredWords, true)
                || in_array('batteriadapt', $requiredWords, true);

            if (! $searchesForAdapter && preg_match('/\bbatteri\s*adapt|\bbatteriadapt|\badapter\b/i', $titleKey)) {
                return true;
            }

            return (bool) preg_match('/\b(?:utan|exkl|exklusive|excl)\s+batter/i', $titleKey);
        }

        return false;
    }

    protected function unitWords(): array
    {
        return ['st', 'stk', 'pack', 'pk', 'mm', 'cm', 'm', 'meter', 'l', 'liter', 'kg', 'g', 'v', 'volt', 'ah', 'w'];
    }

    protected function isNonProductQualifier(string $word): bool
    {
        return is_numeric($word)
            || in_array($word, $this->unitWords(), true)
            || (bool) preg_match('/^\d+(?:v|volt|ah|w|mm|cm|m|l|liter|kg|g)$/', $word);
    }

    protected function searchWords(string $queryKey): array
    {
        return array_values(array_filter(explode(' ', $queryKey), fn (string $word) => mb_strlen($word) >= 2));
    }

    protected function searchKey(string $value): string
    {
        return Str::of($value)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    protected function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($url, '/');
    }
}
