<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_tokens') && Schema::hasColumn('api_tokens', 'expires_at')) {
            DB::statement('CREATE INDEX IF NOT EXISTS api_tokens_expires_at_index ON api_tokens (expires_at)');
        }

        if (Schema::hasTable('arbetsorder_rader') && Schema::hasColumn('arbetsorder_rader', 'artikel')) {
            DB::statement('CREATE INDEX IF NOT EXISTS arbetsorder_rader_artikel_index ON arbetsorder_rader (artikel)');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS api_tokens_expires_at_index');
        DB::statement('DROP INDEX IF EXISTS arbetsorder_rader_artikel_index');
    }
};
