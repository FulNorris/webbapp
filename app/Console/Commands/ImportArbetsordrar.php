<?php

namespace App\Console\Commands;

use App\Models\Arbetsorder;
use App\Services\ArbetsorderParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportArbetsordrar extends Command
{
    protected $signature = 'stuckbema:import-arbetsordrar {file? : Textfil med arbetsorderblock}';

    protected $description = 'Importerar interna Stuckbema-arbetsordrar till normaliserade PostgreSQL-tabeller.';

    public function handle(ArbetsorderParser $parser): int
    {
        $path = $this->argument('file') ?: base_path('database/seeders/data/arbetsordrar.txt');
        if (! is_readable($path)) {
            $this->error("Kunde inte läsa arbetsorderfil: {$path}");

            return self::FAILURE;
        }

        $blocks = $parser->parseBlocks(file_get_contents($path) ?: '');
        $ordersImported = 0;
        $rowsImported = 0;

        foreach ($blocks as $block) {
            DB::transaction(function () use ($block, &$ordersImported, &$rowsImported) {
                $order = Arbetsorder::query()->updateOrCreate(
                    ['arbetsorder_nr' => $block['arbetsorder_nr']],
                    collect($block)->except(['resurser', 'rader'])->all(),
                );

                $order->resurser()->delete();
                $order->rader()->delete();

                foreach ($block['resurser'] as $resource) {
                    $order->resurser()->create($resource);
                }

                foreach ($block['rader'] as $row) {
                    $order->rader()->create([
                        ...$row,
                        'arbetsorder_nr' => $order->arbetsorder_nr,
                    ]);
                    $rowsImported++;
                }

                $ordersImported++;
            });
        }

        $this->info("Importerade {$ordersImported} arbetsordrar och {$rowsImported} arbetsorderrader.");

        return self::SUCCESS;
    }
}
