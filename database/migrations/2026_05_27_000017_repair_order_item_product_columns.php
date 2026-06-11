<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        $this->addColumnIfMissing('product_sku', fn (Blueprint $table) => $table->string('product_sku')->nullable()->index());
        $this->addColumnIfMissing('product_title', fn (Blueprint $table) => $table->string('product_title')->nullable());
        $this->addColumnIfMissing('product_image_path', fn (Blueprint $table) => $table->string('product_image_path')->nullable());
        $this->addColumnIfMissing('product_image_url', fn (Blueprint $table) => $table->string('product_image_url')->nullable());
    }

    public function down(): void
    {
        foreach ([
            'product_sku',
            'product_title',
            'product_image_path',
            'product_image_url',
        ] as $column) {
            if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', $column)) {
                Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    private function addColumnIfMissing(string $columnName, callable $definition): void
    {
        if (! Schema::hasColumn('order_items', $columnName)) {
            Schema::table('order_items', $definition);
        }
    }
};
