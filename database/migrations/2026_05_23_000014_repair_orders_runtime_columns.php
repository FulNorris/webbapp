<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('orders') || DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared("
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS order_number varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_name varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_phone varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS customer_email varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS recipient_name varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS recipient_phone varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS recipient_email varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_address text;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS desired_delivery_date date;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS desired_delivery_time time(0) without time zone;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS assigned_driver_id varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS driver_id varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS driver_name varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS priority varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS notes text;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS internal_comment text;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_enabled boolean NOT NULL DEFAULT false;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_token varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_url varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS tracking_session_id varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS started_at timestamp(0) without time zone;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS last_stopped_at timestamp(0) without time zone;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivered_by varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivered_note text;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS current_location jsonb;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS external_work_order_number varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS original_work_order_snapshot jsonb;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS sms_sent_at timestamp(0) without time zone;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS sms_status varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS sms_error text;
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS created_by varchar(255);
            ALTER TABLE orders ADD COLUMN IF NOT EXISTS updated_by varchar(255);

            CREATE INDEX IF NOT EXISTS orders_order_number_index ON orders (order_number);
            CREATE INDEX IF NOT EXISTS orders_assigned_driver_id_index ON orders (assigned_driver_id);
            CREATE INDEX IF NOT EXISTS orders_driver_id_index ON orders (driver_id);
            CREATE INDEX IF NOT EXISTS orders_tracking_token_index ON orders (tracking_token);
            CREATE INDEX IF NOT EXISTS orders_external_work_order_number_index ON orders (external_work_order_number);
            CREATE INDEX IF NOT EXISTS orders_status_index ON orders (status);

            UPDATE orders SET
                delivery_address = coalesce(delivery_address, adress),
                recipient_name = coalesce(recipient_name, mottagare),
                recipient_phone = coalesce(recipient_phone, tele),
                customer_name = coalesce(customer_name, mottagare),
                customer_phone = coalesce(customer_phone, tele),
                driver_name = coalesce(driver_name, assigned_driver_id);
        ");
    }

    public function down(): void
    {
        // Runtime repair only. Do not remove live order columns.
    }
};
