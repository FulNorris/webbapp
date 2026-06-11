<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        if (! Schema::hasColumn('purchases', 'work_order_number')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->string('work_order_number', 120)->nullable()->after('recipient_phone');
            });
        }

        DB::statement('CREATE INDEX IF NOT EXISTS purchases_work_order_number_index ON purchases (work_order_number)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS purchases_work_order_number_index');

        if (Schema::hasColumn('purchases', 'work_order_number')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropColumn('work_order_number');
            });
        }
    }
};
