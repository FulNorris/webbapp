<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class SystemHealthService
{
    private const REQUIRED_EXTENSIONS = [
        'bcmath',
        'ctype',
        'curl',
        'dom',
        'fileinfo',
        'filter',
        'json',
        'mbstring',
        'openssl',
        'pdo',
        'pdo_pgsql',
        'pgsql',
        'tokenizer',
        'xml',
        'zip',
    ];

    private const EXPECTED_TABLES = [
        'users',
        'orders',
        'order_items',
        'products',
        'purchases',
        'api_tokens',
        'push_subscriptions',
        'internal_messages',
    ];

    private const EXPECTED_INDEXES = [
        'orders' => ['created_at', 'status', 'driver_id', 'tracking_token'],
        'order_items' => ['order_id', 'arbetsorder_nr', 'product_sku'],
        'products' => ['sku'],
        'users' => ['email_key', 'role', 'active'],
        'api_tokens' => ['token', 'user_id', 'expires_at'],
        'work_order_delivery_events' => ['work_order_number'],
        'arbetsordrar' => ['arbetsorder_nr'],
        'arbetsorder_rader' => ['arbetsorder_id', 'artikel'],
    ];

    private const LOG_ISSUE_WINDOW_MINUTES = 60;

    /** @return array<string,mixed> */
    public function report(): array
    {
        $startedAt = microtime(true);
        $checks = [
            'runtime' => $this->runtimeCheck(),
            'database' => $this->databaseCheck(),
            'cache' => $this->cacheCheck(),
            'storage' => $this->storageCheck(),
            'build' => $this->buildCheck(),
            'housekeeping' => $this->housekeepingCheck(),
            'configuration' => $this->configurationCheck(),
            'logs' => $this->logCheck(),
        ];

        $summary = $this->summarize($checks);

        return [
            'ok' => $summary['errors'] === 0,
            'status' => $summary['errors'] > 0 ? 'error' : ($summary['warnings'] > 0 ? 'warning' : 'ok'),
            'service' => 'laravel',
            'environment' => app()->environment(),
            'checkedAt' => now()->toIso8601String(),
            'durationMs' => $this->millisecondsSince($startedAt),
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    /** @return array<string,mixed> */
    private function runtimeCheck(): array
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn (string $extension) => ! extension_loaded($extension)));

        return $this->check(
            $missing === [],
            $missing === [] ? 'PHP runtime är komplett.' : 'PHP runtime saknar obligatoriska extensions.',
            [],
            $missing === [] ? [] : ['missingExtensions' => $missing],
            [
                'phpVersion' => PHP_VERSION,
                'laravelVersion' => app()->version(),
                'requiredExtensions' => self::REQUIRED_EXTENSIONS,
            ]
        );
    }

    /** @return array<string,mixed> */
    private function databaseCheck(): array
    {
        $startedAt = microtime(true);
        try {
            $ping = DB::selectOne('select 1 as ok');
            $latencyMs = $this->millisecondsSince($startedAt);
            $missingTables = array_values(array_filter(self::EXPECTED_TABLES, fn (string $table) => ! Schema::hasTable($table)));
            $missingIndexes = $this->missingIndexes();

            $warnings = [];
            if ($latencyMs > 250) {
                $warnings['latency'] = "Databasping tog {$latencyMs} ms.";
            }
            if ($missingIndexes !== []) {
                $warnings['missingIndexes'] = $missingIndexes;
            }

            return $this->check(
                (int) ($ping->ok ?? 0) === 1 && $missingTables === [],
                $missingTables === [] ? 'Databasen svarar och centrala tabeller finns.' : 'Databasen saknar centrala tabeller.',
                $warnings,
                $missingTables === [] ? [] : ['missingTables' => $missingTables],
                [
                    'connection' => config('database.default'),
                    'latencyMs' => $latencyMs,
                    'driver' => config('database.connections.'.config('database.default').'.driver'),
                ]
            );
        } catch (\Throwable $exception) {
            return $this->check(false, 'Databasen svarar inte.', [], ['message' => $exception->getMessage()]);
        }
    }

    /** @return array<string,array<int,string>> */
    private function missingIndexes(): array
    {
        $missing = [];

        foreach (self::EXPECTED_INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                if (! $this->columnHasIndex($table, $column)) {
                    $missing[$table][] = $column;
                }
            }
        }

        return $missing;
    }

    private function columnHasIndex(string $table, string $column): bool
    {
        $row = DB::selectOne(
            <<<'SQL'
            select exists (
                select 1
                from pg_indexes
                where schemaname = current_schema()
                  and tablename = ?
                  and indexdef ~ ?
            ) as indexed
            SQL,
            [$table, '\m'.preg_quote($column, '/').'\M']
        );

        return (bool) ($row->indexed ?? false);
    }

    /** @return array<string,mixed> */
    private function cacheCheck(): array
    {
        $startedAt = microtime(true);
        $key = 'healthcheck:'.bin2hex(random_bytes(8));
        $value = bin2hex(random_bytes(12));

        try {
            Cache::put($key, $value, 30);
            $read = Cache::get($key);
            Cache::forget($key);
            $latencyMs = $this->millisecondsSince($startedAt);
            $warnings = $latencyMs > 100 ? ['latency' => "Cache roundtrip tog {$latencyMs} ms."] : [];

            return $this->check(
                $read === $value,
                $read === $value ? 'Cache kan skriva, läsa och rensa.' : 'Cache roundtrip gav fel värde.',
                $warnings,
                $read === $value ? [] : ['driver' => config('cache.default')],
                ['driver' => config('cache.default'), 'latencyMs' => $latencyMs]
            );
        } catch (\Throwable $exception) {
            return $this->check(false, 'Cache fungerar inte.', [], ['message' => $exception->getMessage()], [
                'driver' => config('cache.default'),
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function storageCheck(): array
    {
        $paths = [
            'storage' => storage_path(),
            'logs' => storage_path('logs'),
            'frameworkCache' => storage_path('framework/cache'),
            'frameworkSessions' => storage_path('framework/sessions'),
            'frameworkViews' => storage_path('framework/views'),
            'bootstrapCache' => base_path('bootstrap/cache'),
        ];

        $errors = [];
        $details = [];

        foreach ($paths as $name => $path) {
            $exists = File::isDirectory($path);
            $writable = $exists && is_writable($path);
            $details[$name] = [
                'path' => $path,
                'exists' => $exists,
                'writable' => $writable,
            ];

            if (! $exists || ! $writable) {
                $errors[$name] = $exists ? 'Katalogen är inte skrivbar.' : 'Katalogen saknas.';
            }
        }

        return $this->check(
            $errors === [],
            $errors === [] ? 'Runtime-kataloger finns och är skrivbara.' : 'Runtime-kataloger har fel.',
            [],
            $errors,
            $details
        );
    }

    /** @return array<string,mixed> */
    private function buildCheck(): array
    {
        $manifestPath = public_path('build/manifest.json');
        $manifestExists = File::isFile($manifestPath);
        $manifest = $manifestExists ? json_decode((string) File::get($manifestPath), true) : null;
        $assetsPath = public_path('build/assets');
        $assetsExist = File::isDirectory($assetsPath);
        $cacheFiles = [
            'config' => base_path('bootstrap/cache/config.php'),
            'routes' => base_path('bootstrap/cache/routes-v7.php'),
        ];

        $missing = [];
        if (! $manifestExists || ! is_array($manifest)) {
            $missing[] = 'public/build/manifest.json';
        }
        if (! $assetsExist) {
            $missing[] = 'public/build/assets';
        }
        foreach ($cacheFiles as $name => $path) {
            if (! File::isFile($path)) {
                $missing[] = "bootstrap/cache/{$name}";
            }
        }

        return $this->check(
            $missing === [],
            $missing === [] ? 'Frontend-build och Laravel-cache finns.' : 'Build/cache saknar filer.',
            [],
            $missing === [] ? [] : ['missing' => $missing],
            [
                'manifestEntries' => is_array($manifest) ? count($manifest) : 0,
                'manifestUpdatedAt' => $manifestExists ? date(DATE_ATOM, (int) File::lastModified($manifestPath)) : null,
                'cached' => [
                    'config' => app()->configurationIsCached(),
                    'routes' => app()->routesAreCached(),
                    'events' => method_exists(app(), 'eventsAreCached') ? app()->eventsAreCached() : null,
                ],
            ]
        );
    }

    /** @return array<string,mixed> */
    private function housekeepingCheck(): array
    {
        $errors = [];
        $warnings = [];
        $details = [
            'legacyPaths' => [],
            'staleBuildAssets' => [],
            'deployIgnorePresent' => File::isFile(base_path('.deployignore')),
        ];

        $legacyPaths = [
            'resources/original',
            'resources/original/src',
            'resources/original/dist',
        ];

        foreach ($legacyPaths as $path) {
            $absolutePath = base_path($path);
            if (File::exists($absolutePath)) {
                $warnings['legacyPaths'][] = $path;
                $details['legacyPaths'][] = $path;
            }
        }

        if (! $details['deployIgnorePresent']) {
            $errors['deployIgnore'] = '.deployignore saknas i projektroten.';
        }

        $staleBuildAssets = $this->staleBuildAssets();
        if ($staleBuildAssets !== []) {
            $warnings['staleBuildAssets'] = $staleBuildAssets;
            $details['staleBuildAssets'] = $staleBuildAssets;
        }

        return $this->check(
            $errors === [],
            $errors === [] && $warnings === [] ? 'Sanering och release-hygien är uppdaterad.' : 'Sanering eller release-hygien behöver åtgärd.',
            $warnings,
            $errors,
            $details
        );
    }

    /** @return array<int,string> */
    private function staleBuildAssets(): array
    {
        $manifestPath = public_path('build/manifest.json');
        $assetsPath = public_path('build/assets');

        if (! File::isFile($manifestPath) || ! File::isDirectory($assetsPath)) {
            return [];
        }

        $manifest = json_decode((string) File::get($manifestPath), true);
        if (! is_array($manifest)) {
            return [];
        }

        $expected = [];
        foreach ($manifest as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (['file', 'css', 'assets'] as $key) {
                if (! array_key_exists($key, $entry)) {
                    continue;
                }

                foreach ((array) $entry[$key] as $relativePath) {
                    if (is_string($relativePath) && str_starts_with($relativePath, 'assets/')) {
                        $expected[$relativePath] = true;
                    }
                }
            }
        }

        $stale = [];
        foreach (File::files($assetsPath) as $file) {
            $relativePath = 'assets/'.$file->getFilename();
            if (! isset($expected[$relativePath])) {
                $stale[] = 'public/build/'.$relativePath;
            }
        }

        sort($stale);

        return $stale;
    }

    /** @return array<string,mixed> */
    private function configurationCheck(): array
    {
        $warnings = [];

        if (app()->environment('production') && (bool) config('app.debug')) {
            $warnings['debug'] = 'APP_DEBUG är aktivt i produktion.';
        }

        if (app()->environment('production') && (string) config('session.secure') !== '1' && request()->isSecure()) {
            $warnings['sessionSecure'] = 'HTTPS används men SESSION_SECURE_COOKIE verkar inte vara aktiv.';
        }

        if ((bool) env('ALLOW_RESET_TOKEN_RESPONSE', false) && app()->environment('production')) {
            $warnings['resetTokenResponse'] = 'ALLOW_RESET_TOKEN_RESPONSE är aktivt i produktion.';
        }

        return $this->check(
            true,
            $warnings === [] ? 'Konfigurationen har inga kända produktionsvarningar.' : 'Konfigurationen har varningar.',
            $warnings,
            [],
            [
                'debug' => (bool) config('app.debug'),
                'sessionDriver' => config('session.driver'),
                'queueConnection' => config('queue.default'),
                'broadcastConnection' => config('broadcasting.default'),
            ]
        );
    }

    /** @return array<string,mixed> */
    private function logCheck(): array
    {
        $logPath = storage_path('logs/laravel.log');
        if (! File::isFile($logPath)) {
            return $this->check(true, 'Ingen aktiv Laravel-logg hittades.', [], [], ['path' => $logPath]);
        }

        $lines = $this->tailFile($logPath, 300);
        $matches = array_values(array_filter($lines, function (string $line): bool {
            if (! preg_match('/\b(ERROR|WARNING|CRITICAL|ALERT|EMERGENCY)\b/i', $line)) {
                return false;
            }

            if ($this->isIgnoredOperationalLogIssue($line)) {
                return false;
            }

            $timestamp = $this->logLineTimestamp($line);

            return $timestamp === null || $timestamp->greaterThanOrEqualTo(now()->subMinutes(self::LOG_ISSUE_WINDOW_MINUTES));
        }));
        $recent = array_slice($matches, -10);

        return $this->check(
            $matches === [],
            $matches === [] ? 'Aktiv Laravel-logg har inga färska errors/warnings.' : 'Aktiv Laravel-logg innehåller errors/warnings.',
            $matches === [] ? [] : ['recentLogIssues' => $recent],
            [],
            [
                'path' => $logPath,
                'scannedLines' => count($lines),
                'issueCount' => count($matches),
                'issueWindowMinutes' => self::LOG_ISSUE_WINDOW_MINUTES,
                'updatedAt' => date(DATE_ATOM, (int) File::lastModified($logPath)),
            ]
        );
    }

    private function isIgnoredOperationalLogIssue(string $line): bool
    {
        return str_contains($line, 'production.WARNING: Inköpssökning fick oväntad HTTP-status.');
    }

    private function logLineTimestamp(string $line): ?\Illuminate\Support\Carbon
    {
        if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::createFromFormat('Y-m-d H:i:s', $matches[1], config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int,string> */
    private function tailFile(string $path, int $maxLines): array
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        $start = max(0, $lastLine - $maxLines);
        $lines = [];

        for ($line = $start; $line <= $lastLine; $line++) {
            $file->seek($line);
            $content = trim((string) $file->current());
            if ($content !== '') {
                $lines[] = $content;
            }
        }

        return $lines;
    }

    /** @return array<string,mixed> */
    private function check(bool $ok, string $message, array $warnings = [], array $errors = [], array $details = []): array
    {
        return [
            'ok' => $ok && $errors === [],
            'status' => $errors !== [] || ! $ok ? 'error' : ($warnings !== [] ? 'warning' : 'ok'),
            'message' => $message,
            'warnings' => $warnings,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    /** @param array<string,array<string,mixed>> $checks */
    private function summarize(array $checks): array
    {
        $errors = 0;
        $warnings = 0;

        foreach ($checks as $check) {
            if (($check['status'] ?? null) === 'error') {
                $errors++;
            }

            if (($check['status'] ?? null) === 'warning') {
                $warnings++;
            }
        }

        return [
            'checks' => count($checks),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function millisecondsSince(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
