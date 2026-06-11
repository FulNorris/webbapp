<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('purchases') || Schema::hasColumn('purchases', 'recipient_phone')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->string('recipient_phone')->nullable()->after('recipient_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchases') || ! Schema::hasColumn('purchases', 'recipient_phone')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('recipient_phone');
        });
    }
};
