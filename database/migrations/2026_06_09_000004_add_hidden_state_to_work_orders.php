<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['arbetsordrar', 'external_work_orders'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'hidden_at')) {
                    $table->timestamp('hidden_at')->nullable()->index();
                }

                if (! Schema::hasColumn($tableName, 'hidden_by')) {
                    $table->string('hidden_by')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['external_work_orders', 'arbetsordrar'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'hidden_by')) {
                    $table->dropColumn('hidden_by');
                }

                if (Schema::hasColumn($tableName, 'hidden_at')) {
                    $table->dropColumn('hidden_at');
                }
            });
        }
    }
};
