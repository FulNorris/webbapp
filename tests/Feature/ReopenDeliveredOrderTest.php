<?php

namespace Tests\Feature;

use App\Http\Controllers\InternalAppController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReopenDeliveredOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('work_order_delivery_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');

        Schema::create('orders', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('status')->default('created');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->index();
            $table->string('artikel');
            $table->string('antal')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('work_order_number')->nullable()->index();
            $table->integer('requested_quantity')->default(0);
            $table->integer('delivered_quantity')->default(0);
            $table->integer('remaining_quantity')->default(0);
            $table->string('delivered_antal')->nullable();
            $table->decimal('levererat_antal', 12, 3)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('work_order_delivery_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('work_order_number')->index();
            $table->string('order_id')->index();
            $table->unsignedBigInteger('order_item_id')->nullable()->index();
            $table->integer('item_index')->default(0);
            $table->string('artikel');
            $table->string('delivered_antal');
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivered_by')->nullable();
            $table->timestamps();
        });
    }

    public function test_reopening_delivered_order_only_reverts_that_orders_items_and_events(): void
    {
        $firstOrderId = 'ord_first';
        $secondOrderId = 'ord_second';

        $firstItemId = $this->createDeliveredOrderWithItem($firstOrderId);
        $secondItemId = $this->createDeliveredOrderWithItem($secondOrderId);

        $controller = app(InternalAppController::class);
        $method = new \ReflectionMethod($controller, 'reopenDeliveredOrder');
        $method->setAccessible(true);
        $method->invoke($controller, DB::table('orders')->where('id', $firstOrderId)->first(), 'created', (object) ['id' => 'user_1']);

        $firstItem = DB::table('order_items')->where('id', $firstItemId)->first();
        $secondItem = DB::table('order_items')->where('id', $secondItemId)->first();

        $this->assertSame(0, (int) $firstItem->delivered_quantity);
        $this->assertSame(2, (int) $firstItem->remaining_quantity);
        $this->assertNull($firstItem->delivered_at);
        $this->assertSame('0.000', (string) $firstItem->levererat_antal);

        $this->assertSame(2, (int) $secondItem->delivered_quantity);
        $this->assertSame(0, (int) $secondItem->remaining_quantity);
        $this->assertNotNull($secondItem->delivered_at);
        $this->assertSame('2.000', (string) $secondItem->levererat_antal);

        $this->assertDatabaseMissing('work_order_delivery_events', [
            'order_id' => $firstOrderId,
            'order_item_id' => $firstItemId,
        ]);
        $this->assertDatabaseHas('work_order_delivery_events', [
            'order_id' => $secondOrderId,
            'order_item_id' => $secondItemId,
        ]);
    }

    private function createDeliveredOrderWithItem(string $orderId): int
    {
        DB::table('orders')->insert([
            'id' => $orderId,
            'status' => 'delivered',
            'delivered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'artikel' => 'TEST-ART',
            'antal' => '2 st',
            'sort_order' => 0,
            'work_order_number' => '987654',
            'requested_quantity' => 2,
            'delivered_quantity' => 2,
            'remaining_quantity' => 0,
            'delivered_antal' => '2',
            'levererat_antal' => 2,
            'delivered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('work_order_delivery_events')->insert([
            'id' => 'evt_'.$orderId,
            'work_order_number' => '987654',
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'item_index' => 0,
            'artikel' => 'TEST-ART',
            'delivered_antal' => '2',
            'delivered_at' => now(),
            'delivered_by' => 'user_1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $itemId;
    }
}
