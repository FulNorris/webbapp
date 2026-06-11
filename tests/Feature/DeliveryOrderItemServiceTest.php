<?php

namespace Tests\Feature;

use App\Services\DeliveryOrderItemService;
use App\Services\WorkOrderArticleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeliveryOrderItemServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('arbetsorder_rader');
        Schema::dropIfExists('arbetsordrar');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop sequence if exists internal_work_order_number_seq');
            DB::statement('drop sequence if exists arbetsordrar_id_seq cascade');
        }
        Schema::create('arbetsordrar', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('arbetsorder_nr')->unique();
            $table->date('utskriftsdatum')->nullable();
            $table->string('handlaggare')->nullable();
            $table->date('fardig_datum')->nullable();
            $table->string('ordernr')->nullable();
            $table->string('projekt')->nullable();
            $table->date('startdatum')->nullable();
            $table->string('startdatum_text')->nullable();
            $table->string('kontaktperson')->nullable();
            $table->string('telefon')->nullable();
            $table->string('arbetsplats')->nullable();
            $table->string('postadress')->nullable();
            $table->text('arbetsbeskrivning_raw')->nullable();
            $table->text('handskrivet_raw')->nullable();
            $table->boolean('is_kopia')->default(false);
            $table->text('raw_text')->nullable();
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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->string('order_id');
            $table->string('artikel')->nullable();
            $table->string('article')->nullable();
            $table->string('description')->nullable();
            $table->string('antal')->nullable();
            $table->string('quantity')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('work_order_number')->nullable();
            $table->integer('requested_quantity')->default(0);
            $table->integer('delivered_quantity')->default(0);
            $table->integer('remaining_quantity')->default(0);
            $table->unsignedInteger('arbetsorder_nr')->nullable();
            $table->unsignedBigInteger('arbetsorder_rad_id')->nullable();
            $table->string('artikel_normalized')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('status')->default('created');
            $table->string('adress')->nullable();
            $table->string('tele')->nullable();
            $table->string('mottagare')->nullable();
            $table->date('desired_delivery_date')->nullable();
            $table->time('desired_delivery_time')->nullable();
            $table->unsignedBigInteger('internal_work_order_id')->nullable();
            $table->unsignedInteger('internal_work_order_number')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        $workOrderId = DB::table('arbetsordrar')->insertGetId([
            'arbetsorder_nr' => 112591,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('arbetsorder_rader')->insert([
            'arbetsorder_id' => $workOrderId,
            'arbetsorder_nr' => 112591,
            'rad_nr' => 1,
            'artikel' => 'R11B',
            'artikel_normalized' => 'R11B',
            'antal' => 2,
            'enhet' => 'st',
            'raw_line' => '2 st R11B',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_replaces_order_items_and_filters_missing_optional_columns(): void
    {
        app(DeliveryOrderItemService::class)->replace('ord_1', [
            ['artikel' => 'R11B', 'antal' => '1,5 st', 'workOrderNumber' => 112591],
        ]);

        $item = DB::table('order_items')->where('order_id', 'ord_1')->first();

        $this->assertNotNull($item);
        $this->assertSame('R11B', $item->artikel);
        $this->assertSame(2, $item->requested_quantity);
        $this->assertSame(2, $item->remaining_quantity);
        $this->assertSame('112591', $item->work_order_number);
        $this->assertNotNull($item->arbetsorder_rad_id);
    }

    public function test_rejects_article_that_does_not_belong_to_work_order(): void
    {
        $this->expectException(ValidationException::class);

        try {
            app(DeliveryOrderItemService::class)->replace('ord_1', [
                ['artikel' => 'R107', 'antal' => '1', 'workOrderNumber' => 112591],
            ]);
        } finally {
            $this->assertDatabaseMissing('order_items', [
                'order_id' => 'ord_1',
                'artikel' => 'R107',
            ]);
        }
    }

    public function test_accepts_exact_work_order_article_id(): void
    {
        $row = DB::table('arbetsorder_rader')->where('arbetsorder_nr', 112591)->first();

        app(DeliveryOrderItemService::class)->replace('ord_2', [
            ['artikel' => '', 'antal' => '1', 'workOrderNumber' => 112591, 'order_item_id' => $row->id],
        ]);

        $item = DB::table('order_items')->where('order_id', 'ord_2')->first();

        $this->assertSame('R11B', $item->artikel);
        $this->assertSame($row->id, (int) $item->arbetsorder_rad_id);
    }

    public function test_work_order_article_service_returns_dropdown_items(): void
    {
        $result = app(WorkOrderArticleService::class)->responseFor(112591);

        $this->assertSame('112591', $result['work_order_number']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('R11B', $result['items'][0]['article_number']);
        $this->assertStringContainsString('R11B', $result['items'][0]['label']);
    }

    public function test_internal_work_order_is_reused_when_manual_delivery_is_edited(): void
    {
        DB::table('orders')->insert([
            'id' => 'manual_1',
            'status' => 'created',
            'adress' => 'Markvardsgatan 12',
            'tele' => '0700000000',
            'mottagare' => 'Billy Wallén',
            'desired_delivery_date' => now()->toDateString(),
            'desired_delivery_time' => '07:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'id' => 'manual_2',
            'status' => 'created',
            'adress' => 'Sveavägen 1',
            'tele' => '0700000001',
            'mottagare' => 'Nästa mottagare',
            'desired_delivery_date' => now()->toDateString(),
            'desired_delivery_time' => '07:30:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(DeliveryOrderItemService::class)->replace('manual_1', [
            ['artikel' => 'Gipsputs', 'antal' => '2 säck'],
        ]);
        app(DeliveryOrderItemService::class)->replace('manual_2', [
            ['artikel' => 'Gips TRADITION', 'antal' => '1 st'],
        ]);

        $orderAfterCreate = DB::table('orders')->where('id', 'manual_1')->first();
        $secondOrder = DB::table('orders')->where('id', 'manual_2')->first();
        $this->assertSame(1, (int) $orderAfterCreate->internal_work_order_number);
        $this->assertSame(2, (int) $secondOrder->internal_work_order_number);
        $this->assertSame(3, DB::table('arbetsordrar')->count());

        $createdRow = DB::table('arbetsorder_rader')
            ->where('arbetsorder_nr', 1)
            ->first();

        app(DeliveryOrderItemService::class)->replace('manual_1', [
            [
                'artikel' => 'Colle',
                'antal' => '3 st',
                'workOrderNumber' => 1,
                'workOrderArticleId' => $createdRow->id,
                'isInternalWorkOrder' => true,
            ],
        ]);

        $orderAfterEdit = DB::table('orders')->where('id', 'manual_1')->first();
        $editedRow = DB::table('arbetsorder_rader')->where('arbetsorder_nr', 1)->first();
        $orderItem = DB::table('order_items')->where('order_id', 'manual_1')->first();

        $this->assertSame(1, (int) $orderAfterEdit->internal_work_order_number);
        $this->assertSame((int) $orderAfterCreate->internal_work_order_id, (int) $orderAfterEdit->internal_work_order_id);
        $this->assertSame(3, DB::table('arbetsordrar')->count());
        $this->assertSame(1, DB::table('arbetsorder_rader')->where('arbetsorder_nr', 1)->count());
        $this->assertSame('Colle', $editedRow->artikel);
        $this->assertSame('Colle', $orderItem->artikel);
        $this->assertSame(1, (int) $orderItem->arbetsorder_nr);
        $this->assertSame((int) $editedRow->id, (int) $orderItem->arbetsorder_rad_id);
    }
}
