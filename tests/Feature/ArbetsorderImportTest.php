<?php

namespace Tests\Feature;

use App\Http\Controllers\InternalAppController;
use App\Services\ArbetsorderParser;
use App\Services\GipsProductManifestImporter;
use App\Services\LeveransKalkylService;
use App\Services\ProductResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class ArbetsorderImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        Artisan::call('stuckbema:import-arbetsordrar');
    }

    public function test_import_creates_block_32_work_order_and_rows(): void
    {
        $order = DB::table('arbetsordrar')->where('arbetsorder_nr', 112591)->first();

        $this->assertNotNull($order);
        $this->assertSame('Juliette Marchesini', $order->handlaggare);

        $rows = DB::table('arbetsorder_rader')
            ->where('arbetsorder_nr', 112591)
            ->orderBy('rad_nr')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('R11B', $rows[0]->artikel_normalized);
        $this->assertSame(1.0, (float) $rows[0]->antal);
        $this->assertSame('R11A', $rows[1]->artikel_normalized);
        $this->assertSame(1.0, (float) $rows[1]->antal);
    }

    public function test_delivery_on_block_32_calculates_remaining_per_article(): void
    {
        $r11b = DB::table('arbetsorder_rader')->where('arbetsorder_nr', 112591)->where('artikel_normalized', 'R11B')->first();
        $r11a = DB::table('arbetsorder_rader')->where('arbetsorder_nr', 112591)->where('artikel_normalized', 'R11A')->first();
        $order = DB::table('arbetsordrar')->where('arbetsorder_nr', 112591)->first();
        $itemId = $this->insertDeliveryItem('ord_one', $order->id, $r11b->id, 'R11B', 1);

        $calculator = app(LeveransKalkylService::class);
        $itemCalculation = $calculator->calculationForItem(DB::table('order_items')->where('id', $itemId)->first(), 'ord_one');
        $r11aStatus = $calculator->statusForArbetsorderRad($r11a);

        $this->assertEquals(0.0, $itemCalculation['kvar']);
        $this->assertEquals(1.0, $r11aStatus['kvar']);
    }

    public function test_multiple_deliveries_on_same_work_order_are_summed(): void
    {
        $row = DB::table('arbetsorder_rader')->where('arbetsorder_nr', 112591)->where('artikel_normalized', 'R11B')->first();
        $order = DB::table('arbetsordrar')->where('arbetsorder_nr', 112591)->first();
        $this->insertDeliveryItem('ord_previous', $order->id, $row->id, 'R11B', 0.4, 'delivered');
        $currentItemId = $this->insertDeliveryItem('ord_current', $order->id, $row->id, 'R11B', 0.6);

        $calculation = app(LeveransKalkylService::class)
            ->calculationForItem(DB::table('order_items')->where('id', $currentItemId)->first(), 'ord_current');

        $this->assertEquals(0.4, $calculation['levererat_tidigare']);
        $this->assertEquals(1.0, $calculation['levererat_totalt']);
        $this->assertEquals(0.0, $calculation['kvar']);
    }

    public function test_unmatched_article_is_rejected_with_validation_error(): void
    {
        DB::table('orders')->insert([
            'id' => 'ord_unmatched',
            'adress' => 'Karlavägen 24',
            'mottagare' => 'Test',
            'status' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $method = new ReflectionMethod(InternalAppController::class, 'replaceOrderItems');
        $method->setAccessible(true);
        $this->expectException(ValidationException::class);

        try {
            $method->invoke(new InternalAppController(), 'ord_unmatched', [[
                'artikel' => 'R999',
                'antal' => '1',
                'workOrderNumber' => 112591,
            ]]);
        } finally {
            $this->assertDatabaseMissing('order_items', [
                'order_id' => 'ord_unmatched',
                'artikel' => 'R999',
            ]);
        }
    }

    public function test_article_parser_handles_units_and_aliases(): void
    {
        $parser = new ArbetsorderParser();

        $rows = $parser->parseArticleRows([
            '41 lpm TL76',
            '1500 mm kornischvinkel',
            '3 kg silikon till smetform',
            '8 x Nlp12 (nytt namn SLH12)',
        ]);

        $this->assertSame('TL76', $rows[0]['artikel_normalized']);
        $this->assertSame(41.0, $rows[0]['antal']);
        $this->assertSame('lpm', $rows[0]['enhet']);
        $this->assertSame('KORNISCHVINKEL', $rows[1]['artikel_normalized']);
        $this->assertSame('SILIKONTILLSMETFORM', $rows[2]['artikel_normalized']);
        $this->assertSame('SLH12', $rows[3]['artikel_normalized']);
    }

    public function test_product_manifest_uses_manifest_article_number_as_source_of_truth(): void
    {
        $importer = new GipsProductManifestImporter();

        $this->assertSame('TL68-1', $importer->canonicalArticle([
            'article_no' => 'TL68-1',
            'folder' => 'TL',
            'title' => 'Stuckatur taklist TL71 (Löpmeter) - Gipsstuckaturer.se',
            'source_product_url' => 'https://gipsstuckaturer.se/butik/stuckatur-tak/dekorativa-taklister/stuckatur-taklist-tl71-lopmeter/',
            'source_image_url' => 'https://gipsstuckaturer.se/wp-content/uploads/2026/04/TL71.jpg',
        ]));

        $this->assertSame('SLH22-1', $importer->canonicalArticle([
            'article_no' => 'SLH22-1',
            'folder' => 'SLH',
            'title' => 'Hörnlister - Hörnlist SLH21 (dekorlist) - Gipsstuckaturer.se',
            'source_product_url' => 'https://gipsstuckaturer.se/butik/hornlister/hornlister-hornlist-slh21-dekorlist-kopia/',
            'source_image_url' => 'https://gipsstuckaturer.se/wp-content/uploads/2026/04/SLH21.jpg',
        ]));
    }

    public function test_product_resolver_prefers_visible_article_match_over_exact_wrong_sku(): void
    {
        $this->createProductSchema();

        DB::table('products')->insert([
            [
                'sku' => 'R107',
                'folder' => 'R',
                'title' => 'Stuckatur takrosett R18',
                'primary_image_path' => '/opt/www/produkter/R/R107.jpg',
                'primary_image_url' => '/produkter/R/R107.jpg',
                'image_count' => 1,
                'stock_total' => 0,
                'stock_delivered' => 0,
                'stock_available' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'sku' => 'R89-1',
                'folder' => 'R',
                'title' => 'Stuckatur takrosett R107',
                'primary_image_path' => '/opt/www/produkter/R/R89-1.jpg',
                'primary_image_url' => '/produkter/R/R89-1.jpg',
                'image_count' => 1,
                'stock_total' => 0,
                'stock_delivered' => 0,
                'stock_available' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $product = app(ProductResolver::class)->forArticle('R107');

        $this->assertSame('R89-1', $product->sku);
        $this->assertSame('Stuckatur takrosett R107', $product->title);
    }

    private function insertDeliveryItem(string $orderId, int $arbetsorderId, int $arbetsorderRadId, string $article, float $quantity, string $status = 'created'): int
    {
        DB::table('orders')->insert([
            'id' => $orderId,
            'adress' => 'Karlavägen 24',
            'mottagare' => 'Test',
            'status' => $status,
            'delivered_at' => $status === 'delivered' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'artikel' => $article,
            'article' => $article,
            'antal' => (string) $quantity,
            'quantity' => (string) $quantity,
            'sort_order' => 0,
            'work_order_number' => '112591',
            'arbetsorder_nr' => 112591,
            'arbetsorder_id' => $arbetsorderId,
            'arbetsorder_rad_id' => $arbetsorderRadId,
            'artikel_normalized' => $article,
            'enhet' => 'st',
            'levererat_antal' => $quantity,
            'match_status' => 'matched',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        foreach (['order_items', 'orders', 'arbetsorder_rader', 'arbetsorder_resurser', 'arbetsordrar'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('arbetsordrar', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('arbetsorder_nr')->unique();
            $table->date('utskriftsdatum')->nullable();
            $table->string('handlaggare')->nullable();
            $table->date('fardig_datum')->nullable();
            $table->unsignedInteger('ordernr')->nullable();
            $table->unsignedInteger('projekt')->nullable();
            $table->date('startdatum')->nullable();
            $table->string('startdatum_text')->nullable();
            $table->string('kontaktperson')->nullable();
            $table->string('telefon')->nullable();
            $table->string('arbetsplats')->nullable();
            $table->string('postadress')->nullable();
            $table->text('arbetsbeskrivning_raw')->nullable();
            $table->text('handskrivet_raw')->nullable();
            $table->boolean('is_kopia')->default(false);
            $table->text('raw_text');
            $table->timestamps();
        });

        Schema::create('arbetsorder_resurser', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('arbetsorder_id');
            $table->string('namn');
            $table->string('telefon')->nullable();
            $table->timestamps();
        });

        Schema::create('arbetsorder_rader', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('arbetsorder_id');
            $table->unsignedInteger('arbetsorder_nr')->index();
            $table->unsignedInteger('rad_nr')->nullable();
            $table->string('artikel');
            $table->string('artikel_normalized')->index();
            $table->decimal('antal', 12, 3)->nullable();
            $table->string('enhet')->nullable();
            $table->text('raw_line');
            $table->text('kommentar')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('adress');
            $table->string('tele')->nullable();
            $table->string('mottagare');
            $table->string('status')->default('created');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->index();
            $table->string('artikel');
            $table->string('article')->nullable();
            $table->string('description')->nullable();
            $table->string('antal');
            $table->string('quantity')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('work_order_number')->nullable()->index();
            $table->string('product_sku')->nullable();
            $table->string('product_title')->nullable();
            $table->string('product_image_path')->nullable();
            $table->unsignedInteger('requested_quantity')->default(0);
            $table->unsignedInteger('delivered_quantity')->default(0);
            $table->unsignedInteger('remaining_quantity')->default(0);
            $table->unsignedInteger('arbetsorder_nr')->nullable()->index();
            $table->unsignedBigInteger('arbetsorder_id')->nullable();
            $table->unsignedBigInteger('arbetsorder_rad_id')->nullable();
            $table->string('artikel_normalized')->nullable()->index();
            $table->string('enhet')->nullable();
            $table->decimal('levererat_antal', 12, 3)->nullable();
            $table->string('match_status')->nullable();
            $table->text('match_warning')->nullable();
            $table->string('delivered_antal')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    private function createProductSchema(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');

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
    }
}
