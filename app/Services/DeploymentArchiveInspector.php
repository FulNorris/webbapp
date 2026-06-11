<?php

namespace App\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class DeploymentArchiveInspector
{
    private const BLOCKED_EXACT = [
        '.env',
        '.phpunit.result.cache',
        'bootstrap/cache/packages.php',
        'bootstrap/cache/services.php',
    ];

    private const BLOCKED_PREFIXES = [
        '.git/',
        'node_modules/',
        'gips-image-venv/',
        'gips-import-venv/',
        'gips_bilder_downloader/',
        'storage/app/backups/',
        'storage/app/releases/',
        'storage/framework/cache/',
        'storage/framework/sessions/',
        'storage/framework/views/',
        'storage/logs/',
        'public/produkter/',
        'produkter/',
    ];

    private const REPOSITORY_ONLY_BLOCKED_PREFIXES = [
        'vendor/',
        'public/build/',
    ];

    private const BLOCKED_SUFFIXES = [
        '.sql',
        '.sqlite',
        '.zip',
        '.tar',
        '.tar.gz',
        '.tgz',
    ];

    public function inspect(string $path, string $profile = 'release'): array
    {
        if (is_dir($path)) {
            return $this->result($this->directoryEntries($path, $profile), $profile);
        }

        if (is_file($path) && str_ends_with(strtolower($path), '.zip')) {
            return $this->result($this->zipEntries($path), $profile);
        }

        throw new RuntimeException("Kan inte granska deploy-innehåll: {$path}");
    }

    public function isAllowed(string $entry, string $profile = 'release'): bool
    {
        return $this->violationFor($entry, $profile) === null;
    }

    public function violationFor(string $entry, string $profile = 'release'): ?string
    {
        $entry = $this->normalize($entry);

        if ($this->isRuntimeGitkeep($entry)) {
            return null;
        }

        if (in_array($entry, self::BLOCKED_EXACT, true)) {
            return 'blocked_exact';
        }

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($entry, $prefix)) {
                return 'blocked_prefix:'.$prefix;
            }
        }

        if ($profile === 'repository') {
            foreach (self::REPOSITORY_ONLY_BLOCKED_PREFIXES as $prefix) {
                if (str_starts_with($entry, $prefix)) {
                    return 'blocked_prefix:'.$prefix;
                }
            }
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($entry), $suffix)) {
                return 'blocked_suffix:'.$suffix;
            }
        }

        return null;
    }

    private function isRuntimeGitkeep(string $entry): bool
    {
        return in_array($entry, [
            'bootstrap/cache/.gitkeep',
            'storage/app/.gitkeep',
            'storage/framework/cache/.gitkeep',
            'storage/framework/sessions/.gitkeep',
            'storage/framework/views/.gitkeep',
            'storage/logs/.gitkeep',
        ], true);
    }

    public function blockedPatterns(): array
    {
        return [
            'exact' => self::BLOCKED_EXACT,
            'prefixes' => self::BLOCKED_PREFIXES,
            'repository_only_prefixes' => self::REPOSITORY_ONLY_BLOCKED_PREFIXES,
            'suffixes' => self::BLOCKED_SUFFIXES,
        ];
    }

    private function result(array $entries, string $profile): array
    {
        $violations = [];
        foreach ($entries as $entry) {
            if ($reason = $this->violationFor($entry, $profile)) {
                $violations[] = ['path' => $this->normalize($entry), 'reason' => $reason];
            }
        }

        return [
            'ok' => $violations === [],
            'entries' => count($entries),
            'violations' => $violations,
        ];
    }

    private function directoryEntries(string $path, string $profile): array
    {
        $base = rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $entries = [];
        $this->collectDirectoryEntries($base, $base, $entries, $profile);

        return $entries;
    }

    /** @param array<int,string> $entries */
    private function collectDirectoryEntries(string $base, string $directory, array &$entries, string $profile): void
    {
        $items = @scandir($directory);
        if ($items === false) {
            $entries[] = $this->relativeEntry($base, $directory).'/';

            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            $entry = $this->relativeEntry($base, $path);

            if (is_dir($path)) {
                $directoryEntry = $entry.'/';
                if ($this->violationFor($directoryEntry, $profile) !== null) {
                    $entries[] = $directoryEntry;

                    continue;
                }

                $this->collectDirectoryEntries($base, $path, $entries, $profile);

                continue;
            }

            if (is_file($path)) {
                $entries[] = $entry;
            }
        }
    }

    private function relativeEntry(string $base, string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base)));
    }

    private function zipEntries(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP-extension zip saknas; kan inte läsa ZIP-filer.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Kunde inte öppna ZIP: {$path}");
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && ! str_ends_with($name, '/')) {
                $entries[] = $name;
            }
        }
        $zip->close();

        return $entries;
    }

    private function normalize(string $entry): string
    {
        $entry = str_replace('\\', '/', $entry);
        while (str_starts_with($entry, './')) {
            $entry = substr($entry, 2);
        }

        return ltrim($entry, '/');
    }
}
