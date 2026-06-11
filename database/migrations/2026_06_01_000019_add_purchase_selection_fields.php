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

        $columns = [
            'supplier' => fn (Blueprint $table) => $table->string('supplier')->nullable(),
            'store' => fn (Blueprint $table) => $table->string('store')->nullable(),
            'city' => fn (Blueprint $table) => $table->string('city')->nullable(),
            'sku' => fn (Blueprint $table) => $table->string('sku')->nullable(),
            'product_url' => fn (Blueprint $table) => $table->text('product_url')->nullable(),
            'maps_label' => fn (Blueprint $table) => $table->string('maps_label')->nullable(),
            'maps_url' => fn (Blueprint $table) => $table->text('maps_url')->nullable(),
            'unit_price' => fn (Blueprint $table) => $table->decimal('unit_price', 12, 2)->nullable(),
            'currency' => fn (Blueprint $table) => $table->string('currency', 3)->default('SEK'),
            'vat_rate' => fn (Blueprint $table) => $table->decimal('vat_rate', 5, 2)->default(25),
            'total_net' => fn (Blueprint $table) => $table->decimal('total_net', 12, 2)->default(0),
            'total_vat' => fn (Blueprint $table) => $table->decimal('total_vat', 12, 2)->default(0),
            'total_gross' => fn (Blueprint $table) => $table->decimal('total_gross', 12, 2)->default(0),
            'availability_at_selection' => fn (Blueprint $table) => $table->string('availability_at_selection')->nullable(),
            'selected_at' => fn (Blueprint $table) => $table->timestampTz('selected_at')->nullable(),
            'fetched_at' => fn (Blueprint $table) => $table->timestampTz('fetched_at')->nullable(),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('purchases', $column)) {
                Schema::table('purchases', fn (Blueprint $table) => $definition($table));
            }
        }

        DB::statement('CREATE INDEX IF NOT EXISTS purchases_supplier_store_index ON purchases (supplier, store)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchases')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS purchases_supplier_store_index');

        Schema::table('purchases', function (Blueprint $table) {
            foreach ([
                'supplier',
                'store',
                'city',
                'sku',
                'product_url',
                'maps_label',
                'maps_url',
                'unit_price',
                'currency',
                'vat_rate',
                'total_net',
                'total_vat',
                'total_gross',
                'availability_at_selection',
                'selected_at',
                'fetched_at',
            ] as $column) {
                if (Schema::hasColumn('purchases', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
