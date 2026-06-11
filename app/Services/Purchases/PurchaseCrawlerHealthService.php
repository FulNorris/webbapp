<?php

namespace App\Services\Purchases;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PurchaseCrawlerHealthService
{
    public function __construct(private readonly PurchaseProductSearchService $search)
    {
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $startedAt = microtime(true);
        $checks = [];
        $errors = 0;
        $warnings = 0;

        foreach ($this->search->sources() as $source) {
            $check = $this->checkSource($source);
            $checks[] = $check;

            if ($check['status'] === 'error') {
                $errors++;
            }

            if ($check['status'] === 'warning') {
                $warnings++;
            }
        }

        $hornbachExact = $this->search->search('10647390', '10647390');
        $hornbachHasExact = collect($hornbachExact['results'] ?? [])
            ->contains(fn (array $row) => ($row['supplier'] ?? '') === 'Hornbach' && (string) ($row['sku'] ?? '') === '10647390');

        if (! $hornbachHasExact) {
            $errors++;
        }

        return [
            'ok' => $errors === 0,
            'status' => $errors > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'ok'),
            'checkedAt' => now()->toIso8601String(),
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'summary' => [
                'sources' => count($checks),
                'errors' => $errors,
                'warnings' => $warnings,
                'hornbachExactArticle10647390' => $hornbachHasExact,
            ],
            'sources' => $checks,
            'exactArticleSmokeTest' => [
                'query' => '10647390',
                'found' => $hornbachHasExact,
                'resultCount' => count($hornbachExact['results'] ?? []),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function checkSource(object $source): array
    {
        $startedAt = microtime(true);
        $request = $source->searchRequest('10647390');

        try {
            $pending = Http::timeout(4)
                ->connectTimeout(2)
                ->withHeaders($request['headers'] ?? []);

            $response = strtolower($request['method'] ?? 'get') === 'post'
                ? $pending->post($request['url'], $request['json'] ?? [])
                : $pending->get($request['url']);

            if (! $response instanceof Response) {
                return $this->sourceRow($source, 'error', 'Källan gav inget HTTP-svar.', $startedAt);
            }

            if (! $response->successful()) {
                return $this->sourceRow($source, 'warning', 'Källan svarade med HTTP '.$response->status().'.', $startedAt, [
                    'httpStatus' => $response->status(),
                ]);
            }

            $results = $source->normalizeResponse('10647390', $response, now());

            return $this->sourceRow(
                $source,
                $results === [] ? 'warning' : 'ok',
                $results === [] ? 'Källan svarade men gav inga parsade träffar.' : 'Källan svarade och kunde parsas.',
                $startedAt,
                [
                    'httpStatus' => $response->status(),
                    'parsedResults' => count($results),
                    'fallbackUsed' => collect($results)->contains(fn (array $row) => str_contains((string) ($row['dataSource'] ?? ''), 'fallback')),
                ]
            );
        } catch (\Throwable $exception) {
            return $this->sourceRow($source, 'error', 'Källan kunde inte kontrolleras.', $startedAt, [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function sourceRow(object $source, string $status, string $message, float $startedAt, array $details = []): array
    {
        return [
            'supplier' => $source->supplier(),
            'store' => $source->store(),
            'key' => $source->key(),
            'status' => $status,
            'message' => $message,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            'details' => $details,
        ];
    }
}
