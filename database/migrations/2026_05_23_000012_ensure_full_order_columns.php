<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->addPostgresColumns();
            $this->backfillOrderColumns();

            return;
        }

        foreach ($this->columns() as $column => $definition) {
            $this->addColumn('orders', $column, $definition);
        }

        $this->backfillOrderColumns();
    }

    public function down(): void
    {
        // Do not remove live order data columns.
    }

    private function addPostgresColumns(): void
    {
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_number varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_name varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_phone varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_email varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS recipient_name varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS recipient_phone varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS recipient_email varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_address text');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS desired_delivery_date date');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS desired_delivery_time time(0) without time zone');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS assigned_driver_id varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS driver_id varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS driver_name varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS priority varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS notes text');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS internal_comment text');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_enabled boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_token varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_url varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_session_id varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS started_at timestamp(0) without time zone');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS last_stopped_at timestamp(0) without time zone');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivered_by varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivered_note text');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS current_location jsonb');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS external_work_order_number varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS original_work_order_snapshot jsonb');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS sms_sent_at timestamp(0) without time zone');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS sms_status varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS sms_error text');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS created_by varchar(255)');
        DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS updated_by varchar(255)');

        DB::statement('CREATE INDEX IF NOT EXISTS orders_order_number_index ON orders (order_number)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_assigned_driver_id_index ON orders (assigned_driver_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_driver_id_index ON orders (driver_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_tracking_token_index ON orders (tracking_token)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_external_work_order_number_index ON orders (external_work_order_number)');
        DB::statement('CREATE INDEX IF NOT EXISTS orders_status_index ON orders (status)');
    }

    private function columns(): array
    {
        return [
            'order_number' => fn (Blueprint $table) => $table->string('order_number')->nullable()->index(),
            'customer_name' => fn (Blueprint $table) => $table->string('customer_name')->nullable(),
            'customer_phone' => fn (Blueprint $table) => $table->string('customer_phone')->nullable(),
            'customer_email' => fn (Blueprint $table) => $table->string('customer_email')->nullable(),
            'recipient_name' => fn (Blueprint $table) => $table->string('recipient_name')->nullable(),
            'recipient_phone' => fn (Blueprint $table) => $table->string('recipient_phone')->nullable(),
            'recipient_email' => fn (Blueprint $table) => $table->string('recipient_email')->nullable(),
            'delivery_address' => fn (Blueprint $table) => $table->text('delivery_address')->nullable(),
            'desired_delivery_date' => fn (Blueprint $table) => $table->date('desired_delivery_date')->nullable(),
            'desired_delivery_time' => fn (Blueprint $table) => $table->time('desired_delivery_time')->nullable(),
            'assigned_driver_id' => fn (Blueprint $table) => $table->string('assigned_driver_id')->nullable()->index(),
            'driver_id' => fn (Blueprint $table) => $table->string('driver_id')->nullable()->index(),
            'driver_name' => fn (Blueprint $table) => $table->string('driver_name')->nullable(),
            'priority' => fn (Blueprint $table) => $table->string('priority')->nullable(),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
            'internal_comment' => fn (Blueprint $table) => $table->text('internal_comment')->nullable(),
            'tracking_enabled' => fn (Blueprint $table) => $table->boolean('tracking_enabled')->default(false),
            'tracking_token' => fn (Blueprint $table) => $table->string('tracking_token')->nullable()->index(),
            'tracking_url' => fn (Blueprint $table) => $table->string('tracking_url')->nullable(),
            'tracking_session_id' => fn (Blueprint $table) => $table->string('tracking_session_id')->nullable(),
            'started_at' => fn (Blueprint $table) => $table->timestamp('started_at')->nullable(),
            'last_stopped_at' => fn (Blueprint $table) => $table->timestamp('last_stopped_at')->nullable(),
            'delivered_by' => fn (Blueprint $table) => $table->string('delivered_by')->nullable(),
            'delivered_note' => fn (Blueprint $table) => $table->text('delivered_note')->nullable(),
            'current_location' => fn (Blueprint $table) => $table->json('current_location')->nullable(),
            'external_work_order_number' => fn (Blueprint $table) => $table->string('external_work_order_number')->nullable()->index(),
            'original_work_order_snapshot' => fn (Blueprint $table) => $table->json('original_work_order_snapshot')->nullable(),
            'sms_sent_at' => fn (Blueprint $table) => $table->timestamp('sms_sent_at')->nullable(),
            'sms_status' => fn (Blueprint $table) => $table->string('sms_status')->nullable(),
            'sms_error' => fn (Blueprint $table) => $table->text('sms_error')->nullable(),
            'created_by' => fn (Blueprint $table) => $table->string('created_by')->nullable(),
            'updated_by' => fn (Blueprint $table) => $table->string('updated_by')->nullable(),
        ];
    }

    private function addColumn(string $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $table) => $definition($table));
        }
    }

    private function backfillOrderColumns(): void
    {
        DB::statement("
            update orders set
                delivery_address = coalesce(delivery_address, adress),
                recipient_name = coalesce(recipient_name, mottagare),
                recipient_phone = coalesce(recipient_phone, tele),
                customer_name = coalesce(customer_name, mottagare),
                customer_phone = coalesce(customer_phone, tele),
                driver_name = coalesce(driver_name, assigned_driver_id)
        ");
    }
};
