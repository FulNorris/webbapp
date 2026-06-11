<?php

namespace App\Console\Commands;

use App\Services\GipsProductManifestImporter;
use Illuminate\Console\Command;

class ImportGipsProducts extends Command
{
    protected $signature = 'gips:import-products {--manifest=/opt/www/produkter/produkter_manifest.csv : CSV-manifest från bildhämtaren}';

    protected $description = 'Importerar Gipsstuckaturer-produktbilder till products och product_images.';

    public function handle(GipsProductManifestImporter $importer): int
    {
        $manifest = (string) $this->option('manifest');
        $result = $importer->import($manifest);

        if (($result['products'] ?? 0) === 0 && ! is_readable($manifest)) {
            $this->error("Kunde inte läsa manifest: {$manifest}");

            return self::FAILURE;
        }

        $this->info("Importerade {$result['products']} produkter och {$result['images']} bilder från {$result['manifest']}.");

        return self::SUCCESS;
    }
}
