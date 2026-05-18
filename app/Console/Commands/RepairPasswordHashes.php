<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RepairPasswordHashes extends Command
{
    protected $signature = 'users:repair-password-hashes
        {--password= : Temporary password to apply to all repaired users}
        {--only= : Repair one user by email address}
        {--dry-run : Show affected users without changing the database}';

    protected $description = 'Replace invalid legacy password values with Laravel bcrypt hashes and force password change.';

    public function handle(): int
    {
        $query = DB::table('users')->orderBy('email');

        if ($email = $this->option('only')) {
            $query->where('email_key', strtolower(trim((string) $email)));
        }

        $users = $query->get(['id', 'email', 'password_hash']);
        $invalidUsers = $users->filter(fn (object $user) => ! $this->isSupportedHash($user->password_hash));

        if ($invalidUsers->isEmpty()) {
            $this->info('Alla användarlösenord använder ett stödformat.');

            return self::SUCCESS;
        }

        foreach ($invalidUsers as $user) {
            if ($this->option('dry-run')) {
                $this->line("Skulle återställa: {$user->email}");
                continue;
            }

            $password = (string) ($this->option('password') ?: Str::password(14));
            DB::table('users')->where('id', $user->id)->update([
                'password_hash' => Hash::make($password),
                'is_first_login' => true,
                'updated_at' => now(),
            ]);

            $this->line($this->option('password')
                ? "Återställde: {$user->email}"
                : "Återställde: {$user->email} | tillfälligt lösenord: {$password}");
        }

        $this->info($this->option('dry-run')
            ? $invalidUsers->count().' användare behöver återställas.'
            : $invalidUsers->count().' användare återställdes.');

        return self::SUCCESS;
    }

    private function isSupportedHash(?string $hash): bool
    {
        if (! is_string($hash) || trim($hash) === '') {
            return false;
        }

        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
            return true;
        }

        return str_starts_with($hash, '$argon2i$') || str_starts_with($hash, '$argon2id$');
    }
}
