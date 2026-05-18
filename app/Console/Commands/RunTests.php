<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RunTests extends Command
{
    protected $signature = 'test {--filter= : Filter tests by name} {--testsuite= : Run one PHPUnit testsuite}';

    protected $description = 'Run the project test suite when PHPUnit is installed.';

    public function handle(): int
    {
        $phpunit = base_path('vendor/bin/phpunit');
        if (! is_file($phpunit)) {
            $this->warn('PHPUnit är inte installerat i detta projekt. Inga tester kördes.');

            return self::SUCCESS;
        }

        $command = [$phpunit];
        if ($this->option('filter')) {
            $command[] = '--filter';
            $command[] = (string) $this->option('filter');
        }
        if ($this->option('testsuite')) {
            $command[] = '--testsuite';
            $command[] = (string) $this->option('testsuite');
        }

        $process = new Process($command, base_path());
        $process->setTimeout(null);
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        return $process->getExitCode() ?? self::FAILURE;
    }
}
