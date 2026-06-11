<?php

namespace App\Services\Purchases;

use App\Services\Purchases\Sources\BauhausStockholmSource;
use App\Services\Purchases\Sources\BeijerStockholmSource;
use App\Services\Purchases\Sources\BiltemaBreddenSource;
use App\Services\Purchases\Sources\BygmaStockholmSource;
use App\Services\Purchases\Sources\HornbachStockholmSource;
use App\Services\Purchases\Sources\JulaStockholmSource;
use App\Services\Purchases\Sources\ProductSearchSourceInterface;
use App\Services\Purchases\Sources\SwedolStockholmSource;
use App\Services\Purchases\Sources\XlbyggStockholmSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseProductSearchService
{
    private const CACHE_SECONDS = 120;

    private const SOURCE_CACHE_SECONDS = 300;

    private const SOURCE_STALE_SECONDS = 86400;

    private const TIMEOUT_SECONDS = 3;

    private const CONNECT_TIMEOUT_SECONDS = 2;

    private const MAX_RESULTS = 50;

    /** @return array{query:string,articleNumber:?string,results:array<int,array<string,mixed>>,errors:array<int,array<string,string>>,fetchedAt:string} */
    public function search(string $query, ?string $articleNumber = null, ?float $userLatitude = null, ?float $userLongitude = null): array
    {
        $articleNumber = $this->sanitizeArticleNumber($articleNumber);
        $query = $this->sanitizeQuery($query, $articleNumber);
        $locationKey = $this->locationCacheKey($userLatitude, $userLongitude);
        $cacheKey = 'purchase-search:'.hash('sha256', Str::lower($query).'|'.Str::lower((string) $articleNumber).'|'.$locationKey);

        return Cache::remember($cacheKey, self::CACHE_SECONDS, function () use ($query, $articleNumber, $userLatitude, $userLongitude) {
            $fetchedAt = now();
            $sources = $this->activeSources();
            $errors = [];
            $results = [];

            foreach ($this->searchQueries($query, $articleNumber) as $searchQuery) {
                $results = array_merge($results, $this->collectResults($sources, $searchQuery, $fetchedAt, $errors));
            }

            $results = $this->dedupeResults($results);
            $errors = $this->dedupeErrors($errors);

            $results = $this->filterRelevantResults($query, $results, $articleNumber);
            $results = $this->withDistance($results, $userLatitude, $userLongitude);

            $results = $this->sortResults($results, $articleNumber);
            $results = $this->limitUnpricedResults($results);

            return [
                'query' => $query,
                'articleNumber' => $articleNumber,
                'results' => array_slice($results, 0, self::MAX_RESULTS),
                'errors' => $errors,
                'fetchedAt' => $fetchedAt->format(DATE_ATOM),
            ];
        });
    }

    /** @return array<string,mixed> */
    private function fetchSources(array $sources, string $query): array
    {
        try {
            return Http::pool(function (Pool $pool) use ($sources, $query) {
                $requests = [];
                foreach ($sources as $source) {
                    $request = $source->searchRequest($query);
                    $pending = $pool
                        ->as($source->key())
                        ->timeout(self::TIMEOUT_SECONDS)
                        ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                        ->withHeaders($request['headers'] ?? []);

                    if (mb_strtolower($request['method'] ?? 'get') === 'post') {
                        $requests[] = $pending->post($request['url'], $request['json'] ?? []);
                        continue;
                    }

                    $requests[] = $pending->get($request['url']);
                }

                return $requests;
            });
        } catch (ConnectionException $exception) {
            Log::info('Inköpssökning avbröts av anslutningsfel.', [
                'query_hash' => hash('sha256', $query),
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function collectResults(array $sources, string $query, \DateTimeInterface $fetchedAt, array &$errors): array
    {
        $results = [];
        $sourcesToFetch = [];

        foreach ($sources as $source) {
            $cached = Cache::get($this->sourceCacheKey($source, $query));
            if (is_array($cached)) {
                $results = array_merge($results, $cached);
                continue;
            }

            $sourcesToFetch[] = $source;
        }

        if ($sourcesToFetch === []) {
            return $results;
        }

        $responses = $this->fetchSources($sourcesToFetch, $query);

        foreach ($sourcesToFetch as $source) {
            $response = $responses[$source->key()] ?? null;
            if (! $response instanceof Response) {
                $errors[] = $this->sourceError(
                    $source,
                    $this->appendStaleSourceResults($source, $query, $results)
                        ? 'Butiken svarade inte just nu, visar senast hämtade träffar.'
                        : 'Kunde inte kontrollera butiken just nu.'
                );
                Log::info('Inköpssökning misslyckades mot butik.', [
                    'supplier' => $source->supplier(),
                    'store' => $source->store(),
                    'query_hash' => hash('sha256', $query),
                    'error' => is_object($response) ? $response::class : gettype($response),
                ]);
                continue;
            }

            if (! $response->successful()) {
                $errors[] = $this->sourceError(
                    $source,
                    $this->appendStaleSourceResults($source, $query, $results)
                        ? 'Butiken blockerade eller svarade fel, visar senast hämtade träffar.'
                        : 'Kunde inte kontrollera butiken just nu.'
                );
                Log::info('Inköpssökning fick oväntad HTTP-status.', [
                    'supplier' => $source->supplier(),
                    'store' => $source->store(),
                    'status' => $response->status(),
                    'query_hash' => hash('sha256', $query),
                ]);
                continue;
            }

            try {
                $sourceResults = $source->normalizeResponse($query, $response, $fetchedAt);
                Cache::put($this->sourceCacheKey($source, $query), $sourceResults, self::SOURCE_CACHE_SECONDS);
                Cache::put($this->sourceStaleCacheKey($source, $query), $sourceResults, self::SOURCE_STALE_SECONDS);

                if ($sourceResults === []) {
                    Log::debug('Inköpssökning gav tomt resultat för butik.', [
                        'supplier' => $source->supplier(),
                        'store' => $source->store(),
                        'query_hash' => hash('sha256', $query),
                    ]);
                }
                $results = array_merge($results, $sourceResults);
            } catch (\Throwable $exception) {
                $errors[] = $this->sourceError(
                    $source,
                    $this->appendStaleSourceResults($source, $query, $results)
                        ? 'Butiken kunde inte parsas just nu, visar senast hämtade träffar.'
                        : 'Kunde inte kontrollera butiken just nu.'
                );
                Log::info('Inköpssökning kunde inte parsa butikssvar.', [
                    'supplier' => $source->supplier(),
                    'store' => $source->store(),
                    'query_hash' => hash('sha256', $query),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /** @return array<int,string> */
    private function searchQueries(string $query, ?string $articleNumber): array
    {
        return collect([$query, $articleNumber])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->unique(fn (string $value) => Str::lower($value))
            ->values()
            ->all();
    }

    private function dedupeResults(array $results): array
    {
        $unique = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = implode('|', [
                Str::lower((string) ($row['supplier'] ?? '')),
                Str::lower((string) ($row['store'] ?? '')),
                Str::lower((string) ($row['sku'] ?? '')),
                Str::lower((string) ($row['title'] ?? '')),
                Str::lower((string) ($row['productUrl'] ?? '')),
            ]);

            if ($key === '||||') {
                continue;
            }

            if (! isset($unique[$key])) {
                $unique[$key] = $row;
                continue;
            }

            if (! is_numeric($unique[$key]['price'] ?? null) && is_numeric($row['price'] ?? null)) {
                $unique[$key] = $row;
            }
        }

        return array_values($unique);
    }

    private function dedupeErrors(array $errors): array
    {
        $unique = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $key = Str::lower(($error['supplier'] ?? '').'|'.($error['store'] ?? '').'|'.($error['message'] ?? ''));
            $unique[$key] = $error;
        }

        return array_values($unique);
    }

    private function appendStaleSourceResults(ProductSearchSourceInterface $source, string $query, array &$results): bool
    {
        $stale = Cache::get($this->sourceStaleCacheKey($source, $query));
        if (! is_array($stale) || $stale === []) {
            return false;
        }

        foreach ($stale as $row) {
            if (is_array($row)) {
                $row['stale'] = true;
                $results[] = $row;
            }
        }

        return true;
    }

    private function sourceCacheKey(ProductSearchSourceInterface $source, string $query): string
    {
        return 'purchase-source-search:'.$source->key().':'.hash('sha256', Str::lower($query));
    }

    private function sourceStaleCacheKey(ProductSearchSourceInterface $source, string $query): string
    {
        return 'purchase-source-search-stale:'.$source->key().':'.hash('sha256', Str::lower($query));
    }

    /** @return array<int,ProductSearchSourceInterface> */
    public function sources(): array
    {
        return $this->activeSources();
    }

    /** @return array<int,ProductSearchSourceInterface> */
    private function activeSources(): array
    {
        return array_values(array_filter([
            new BauhausStockholmSource(),
            new BygmaStockholmSource(),
            new JulaStockholmSource(),
            new BiltemaBreddenSource(),
            new BeijerStockholmSource(),
            new SwedolStockholmSource(),
            new XlbyggStockholmSource(),
            new HornbachStockholmSource(),
        ], fn (ProductSearchSourceInterface $source) => $source->isActive()));
    }

    private function sanitizeQuery(string $query, ?string $articleNumber = null): string
    {
        $query = Str::of($query)
            ->replaceMatches('/[\x00-\x1F\x7F]/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();

        if ($query === '' && $articleNumber) {
            $query = $articleNumber;
        }

        if (mb_strlen($query) < 2) {
            throw ValidationException::withMessages([
                'q' => 'Söktext eller artikelnummer måste vara minst 2 tecken.',
            ]);
        }

        if (mb_strlen($query) > 80) {
            throw ValidationException::withMessages([
                'q' => 'Söktexten får vara max 80 tecken.',
            ]);
        }

        return $query;
    }

    private function sanitizeArticleNumber(?string $articleNumber): ?string
    {
        $articleNumber = Str::of((string) $articleNumber)
            ->replaceMatches('/[\x00-\x1F\x7F]/u', '')
            ->replaceMatches('/\s+/u', '')
            ->trim()
            ->toString();

        if ($articleNumber === '') {
            return null;
        }

        if (mb_strlen($articleNumber) < 2) {
            throw ValidationException::withMessages([
                'article_number' => 'Artikelnummer måste vara minst 2 tecken.',
            ]);
        }

        if (mb_strlen($articleNumber) > 120) {
            throw ValidationException::withMessages([
                'article_number' => 'Artikelnummer får vara max 120 tecken.',
            ]);
        }

        return $articleNumber;
    }

    private function sourceError(ProductSearchSourceInterface $source, string $message): array
    {
        return [
            'supplier' => $source->supplier(),
            'store' => $source->store(),
            'message' => $message,
        ];
    }

    private function sortResults(array $results, ?string $articleNumber = null): array
    {
        usort($results, function (array $a, array $b) use ($articleNumber) {
            if ($articleNumber) {
                $articleMatch = ((float) ($b['articleMatchScore'] ?? 0)) <=> ((float) ($a['articleMatchScore'] ?? 0));
                if ($articleMatch !== 0) {
                    return $articleMatch;
                }
            }

            $match = ((float) ($b['matchScore'] ?? 0)) <=> ((float) ($a['matchScore'] ?? 0));
            if ($match !== 0) {
                return $match;
            }

            $distance = ($a['distanceRank'] ?? 999999) <=> ($b['distanceRank'] ?? 999999);
            if ($distance !== 0) {
                return $distance;
            }

            $availability = ($a['availabilityRank'] ?? 5) <=> ($b['availabilityRank'] ?? 5);
            if ($availability !== 0) {
                return $availability;
            }

            $aHasPrice = is_numeric($a['price'] ?? null);
            $bHasPrice = is_numeric($b['price'] ?? null);
            if ($aHasPrice !== $bHasPrice) {
                return $aHasPrice ? -1 : 1;
            }

            if ($aHasPrice && $bHasPrice) {
                $price = ((float) $a['price']) <=> ((float) $b['price']);
                if ($price !== 0) {
                    return $price;
                }
            }

            return strcmp((string) ($a['supplier'] ?? ''), (string) ($b['supplier'] ?? ''));
        });

        return $results;
    }

    private function filterRelevantResults(string $query, array $results, ?string $articleNumber = null): array
    {
        $rows = array_values(array_filter(array_map(function (array $row) use ($articleNumber) {
            $row['articleNumber'] = $row['sku'] ?? null;
            $row['articleMatchScore'] = $this->articleMatchScore($row, $articleNumber);
            $row['exactArticleMatch'] = $articleNumber ? $row['articleMatchScore'] >= 1.0 : false;

            return $row;
        }, $results), fn (array $row) => $this->hasUsablePrice($row)));

        if ($articleNumber) {
            $exactArticleMatches = array_values(array_filter(
                $rows,
                fn (array $row) => ((float) ($row['articleMatchScore'] ?? 0)) >= 0.9
            ));

            if ($exactArticleMatches !== []) {
                return $exactArticleMatches;
            }

            $partialArticleMatches = array_values(array_filter(
                $rows,
                fn (array $row) => ((float) ($row['articleMatchScore'] ?? 0)) >= 0.65
                    && $this->matchesRequiredWords($query, (string) ($row['title'] ?? ''))
            ));

            if ($partialArticleMatches !== []) {
                return $partialArticleMatches;
            }
        }

        return array_values(array_filter(
            $rows,
            fn (array $row) => $this->matchesRequiredWords($query, (string) ($row['title'] ?? ''))
        ));
    }

    private function hasUsablePrice(array $row): bool
    {
        return is_numeric($row['price'] ?? null) && (float) $row['price'] > 0;
    }

    private function searchKey(string $value): string
    {
        return Str::of($value)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    private function articleKey(?string $value): string
    {
        return Str::of((string) $value)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    private function articleMatchScore(array $row, ?string $articleNumber): float
    {
        if (! $articleNumber) {
            return 0.0;
        }

        $needle = $this->articleKey($articleNumber);
        if ($needle === '') {
            return 0.0;
        }

        $sku = $this->articleKey((string) ($row['sku'] ?? ''));
        if ($sku !== '') {
            if ($sku === $needle) {
                return 1.0;
            }

            if (str_contains($sku, $needle) || str_contains($needle, $sku)) {
                return 0.9;
            }
        }

        $title = $this->articleKey((string) ($row['title'] ?? ''));
        if ($title !== '' && str_contains($title, $needle)) {
            return 0.65;
        }

        return 0.0;
    }

    private function withDistance(array $results, ?float $userLatitude, ?float $userLongitude): array
    {
        if ($userLatitude === null || $userLongitude === null) {
            return array_map(function (array $row): array {
                $row['distanceKm'] = null;
                $row['distanceRank'] = null;

                return $row;
            }, $results);
        }

        return array_map(function (array $row) use ($userLatitude, $userLongitude): array {
            $storeLatitude = $row['storeLatitude'] ?? null;
            $storeLongitude = $row['storeLongitude'] ?? null;

            if (! is_numeric($storeLatitude) || ! is_numeric($storeLongitude)) {
                $row['distanceKm'] = null;
                $row['distanceRank'] = null;

                return $row;
            }

            $distanceKm = $this->distanceKm($userLatitude, $userLongitude, (float) $storeLatitude, (float) $storeLongitude);
            $row['distanceKm'] = round($distanceKm, 1);
            $row['distanceRank'] = (int) floor($distanceKm / 5);

            return $row;
        }, $results);
    }

    private function distanceKm(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $earthRadiusKm = 6371.0;
        $latDelta = deg2rad($toLatitude - $fromLatitude);
        $lngDelta = deg2rad($toLongitude - $fromLongitude);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function locationCacheKey(?float $latitude, ?float $longitude): string
    {
        if ($latitude === null || $longitude === null) {
            return 'no-location';
        }

        return number_format($latitude, 2, '.', '').','.number_format($longitude, 2, '.', '');
    }

    private function searchWords(string $queryKey): array
    {
        return array_values(array_filter(explode(' ', $queryKey), fn (string $word) => mb_strlen($word) >= 2));
    }

    private function matchesRequiredWords(string $query, string $title): bool
    {
        $titleKey = $this->searchKey($title);
        $requiredWords = $this->requiredQueryWords($query);

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

    private function hasContradictingPhrase(string $titleKey, array $requiredWords): bool
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

    private function requiredQueryWords(string $query): array
    {
        return array_values(array_filter(
            $this->searchWords($this->searchKey($query)),
            fn (string $word) => ! $this->isNonProductQualifier($word)
        ));
    }

    private function isNonProductQualifier(string $word): bool
    {
        return is_numeric($word)
            || in_array($word, $this->unitWords(), true)
            || (bool) preg_match('/^\d+(?:v|volt|ah|w|mm|cm|m|l|liter|kg|g)$/', $word);
    }

    private function unitWords(): array
    {
        return ['st', 'stk', 'pack', 'pk', 'mm', 'cm', 'm', 'meter', 'l', 'liter', 'kg', 'g', 'v', 'volt', 'ah', 'w'];
    }

    private function limitUnpricedResults(array $results): array
    {
        return array_values(array_filter($results, fn (array $row) => $this->hasUsablePrice($row)));
    }
}
