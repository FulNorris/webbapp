<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            if (! Schema::hasColumn('purchases', 'image_url')) {
                $table->text('image_url')->nullable()->after('product_url');
            }

            if (! Schema::hasColumn('purchases', 'thumbnail_path')) {
                $table->string('thumbnail_path')->nullable()->after('image_url');
            }

            if (! Schema::hasColumn('purchases', 'thumbnail_hash')) {
                $table->string('thumbnail_hash', 64)->nullable()->after('thumbnail_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        Schema::table('purchases', function (Blueprint $table) {
            foreach (['thumbnail_hash', 'thumbnail_path', 'image_url'] as $column) {
                if (Schema::hasColumn('purchases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
