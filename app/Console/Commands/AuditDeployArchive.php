<?php

namespace App\Console\Commands;

use App\Services\DeploymentArchiveInspector;
use Illuminate\Console\Command;

class AuditDeployArchive extends Command
{
    protected $signature = 'deploy:audit
        {path=. : Katalog eller ZIP-fil att granska}
        {--profile=release : Granskningsprofil: release eller repository}';

    protected $description = 'Granskar deploy-/ZIP-innehåll för hemligheter, beroendemappar, cache och backuper.';

    public function handle(DeploymentArchiveInspector $inspector): int
    {
        $path = (string) $this->argument('path');
        $target = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        $profile = (string) $this->option('profile');
        if (! in_array($profile, ['release', 'repository'], true)) {
            $this->error('Ogiltig profil. Använd release eller repository.');

            return self::FAILURE;
        }

        $result = $inspector->inspect($target, $profile);

        if ($result['ok']) {
            $this->info("Deploy-innehållet är godkänt med {$profile}-profil ({$result['entries']} filer granskade).");

            return self::SUCCESS;
        }

        $this->error('Deploy-innehållet innehåller blockerade filer:');
        foreach (array_slice($result['violations'], 0, 100) as $violation) {
            $this->line("- {$violation['path']} ({$violation['reason']})");
        }
        if (count($result['violations']) > 100) {
            $this->line('... ytterligare '.(count($result['violations']) - 100).' blockerade filer visas inte.');
        }

        return self::FAILURE;
    }
}
