<?php

use App\Services\GipsProductManifestImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->products();
        $this->productImages();
        $this->stockMovements();
        $this->orderItemInventoryColumns();
        $this->importProductManifest();
    }

    public function down(): void
    {
        foreach ([
            'product_sku',
            'product_title',
            'product_image_path',
            'requested_quantity',
            'delivered_quantity',
            'remaining_quantity',
        ] as $column) {
            if (Schema::hasColumn('order_items', $column)) {
                Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
    }

    private function products(): void
    {
        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->string('sku')->primary();
                $table->string('folder')->nullable()->index();
                $table->string('title')->nullable();
                $table->string('primary_image_path')->nullable();
                $table->string('primary_image_url')->nullable();
                $table->unsignedInteger('image_count')->default(0);
                $table->unsignedInteger('stock_total')->default(0);
                $table->unsignedInteger('stock_delivered')->default(0);
                $table->unsignedInteger('stock_available')->default(0);
                $table->timestamps();
            });

            return;
        }

        foreach ([
            'folder' => fn (Blueprint $table) => $table->string('folder')->nullable()->index(),
            'title' => fn (Blueprint $table) => $table->string('title')->nullable(),
            'primary_image_path' => fn (Blueprint $table) => $table->string('primary_image_path')->nullable(),
            'primary_image_url' => fn (Blueprint $table) => $table->string('primary_image_url')->nullable(),
            'image_count' => fn (Blueprint $table) => $table->unsignedInteger('image_count')->default(0),
            'stock_total' => fn (Blueprint $table) => $table->unsignedInteger('stock_total')->default(0),
            'stock_delivered' => fn (Blueprint $table) => $table->unsignedInteger('stock_delivered')->default(0),
            'stock_available' => fn (Blueprint $table) => $table->unsignedInteger('stock_available')->default(0),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ] as $column => $definition) {
            $this->addColumn('products', $column, $definition);
        }
    }

    private function productImages(): void
    {
        if (! Schema::hasTable('product_images')) {
            Schema::create('product_images', function (Blueprint $table) {
                $table->id();
                $table->string('product_sku')->index();
                $table->unsignedInteger('image_index')->default(0);
                $table->string('folder')->nullable()->index();
                $table->string('image_path');
                $table->string('image_url')->nullable();
                $table->text('source_page')->nullable();
                $table->text('source_image')->nullable();
                $table->string('title')->nullable();
                $table->timestamps();
                $table->unique(['product_sku', 'image_path']);
            });

            return;
        }

        foreach ([
            'product_sku' => fn (Blueprint $table) => $table->string('product_sku')->nullable()->index(),
            'image_index' => fn (Blueprint $table) => $table->unsignedInteger('image_index')->default(0),
            'folder' => fn (Blueprint $table) => $table->string('folder')->nullable()->index(),
            'image_path' => fn (Blueprint $table) => $table->string('image_path')->nullable(),
            'image_url' => fn (Blueprint $table) => $table->string('image_url')->nullable(),
            'source_page' => fn (Blueprint $table) => $table->text('source_page')->nullable(),
            'source_image' => fn (Blueprint $table) => $table->text('source_image')->nullable(),
            'title' => fn (Blueprint $table) => $table->string('title')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ] as $column => $definition) {
            $this->addColumn('product_images', $column, $definition);
        }
    }

    private function stockMovements(): void
    {
        if (Schema::hasTable('stock_movements')) {
            return;
        }

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('product_sku')->index();
            $table->string('order_id')->nullable()->index();
            $table->unsignedBigInteger('order_item_id')->nullable()->index();
            $table->string('work_order_number')->nullable()->index();
            $table->integer('quantity_delta');
            $table->string('type')->default('delivered')->index();
            $table->string('created_by')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    private function orderItemInventoryColumns(): void
    {
        foreach ([
            'product_sku' => fn (Blueprint $table) => $table->string('product_sku')->nullable()->index(),
            'product_title' => fn (Blueprint $table) => $table->string('product_title')->nullable(),
            'product_image_path' => fn (Blueprint $table) => $table->string('product_image_path')->nullable(),
            'requested_quantity' => fn (Blueprint $table) => $table->unsignedInteger('requested_quantity')->default(0),
            'delivered_quantity' => fn (Blueprint $table) => $table->unsignedInteger('delivered_quantity')->default(0),
            'remaining_quantity' => fn (Blueprint $table) => $table->unsignedInteger('remaining_quantity')->default(0),
        ] as $column => $definition) {
            $this->addColumn('order_items', $column, $definition);
        }
    }

    private function importProductManifest(): void
    {
        $manifest = base_path('produkter/produkter_manifest.csv');
        app(GipsProductManifestImporter::class)->import($manifest);
    }

    private function addColumn(string $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $table) => $definition($table));
        }
    }

    private function imageUrl(string $imagePath): ?string
    {
        $base = rtrim(str_replace('\\', '/', base_path('produkter')), '/').'/';
        $path = str_replace('\\', '/', $imagePath);
        if (! str_starts_with($path, $base)) {
            return null;
        }

        $relative = substr($path, strlen($base));
        $parts = array_map('rawurlencode', explode('/', $relative));

        return '/produkter/'.implode('/', $parts);
    }
};
