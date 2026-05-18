<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private array $legacyRoles = ['owner', 'manager', 'admin', 'supervisor', 'driver', 'worker', 'viewer'];

    private array $currentRoles = ['firmatecknare', 'admin', 'arbetsledare', 'personal', 'support', 'forare', 'viewer', 'kund'];

    public function up(): void
    {
        $this->replaceConstraint(array_values(array_unique([...$this->legacyRoles, ...$this->currentRoles])));
    }

    public function down(): void
    {
        $this->replaceConstraint($this->legacyRoles);
    }

    private function replaceConstraint(array $roles): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $quotedRoles = collect($roles)
            ->map(fn (string $role) => "'".str_replace("'", "''", $role)."'")
            ->implode(', ');

        DB::statement('alter table users drop constraint if exists users_role_check');
        DB::statement("alter table users add constraint users_role_check check (role in ({$quotedRoles}))");
    }
};
