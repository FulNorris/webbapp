<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->users();
        $this->apiTokens();
        $this->systemSettings();
        $this->people();
        $this->orders();
        $this->orderItems();
        $this->externalWorkOrders();
        $this->workOrderDeliveryEvents();
        $this->trackingLinks();
        $this->pushSubscriptions();

        DB::table('system_settings')->updateOrInsert(['id' => 1], [
            'app_title' => 'Stuckbema Leveransdokument',
            'company_name' => 'Stuckbema',
            'delivery_title' => 'Leveransdokument',
            'order_number_prefix' => 'LEV',
            'allow_push_notifications' => true,
            'updated_at' => now(),
        ]);
    }

    private function addColumn(string $table, string $column, callable $definition): void
    {
        if (! Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $table) => $definition($table));
        }
    }

    private function users(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('email')->unique();
                $table->string('email_key')->unique();
                $table->string('first_name')->default('Användare');
                $table->string('last_name')->default('');
                $table->string('role')->default('worker');
                $table->string('password_hash');
                $table->boolean('is_first_login')->default(true);
                $table->boolean('active')->default(true);
                $table->string('phone')->nullable();
                $table->string('image_path')->nullable();
                $table->string('photo_url')->nullable();
                $table->string('person_id')->nullable();
                $table->string('visibility')->default('offline');
                $table->timestamp('last_seen_at')->nullable();
                $table->string('reset_token')->nullable();
                $table->timestamp('reset_token_expires')->nullable();
                $table->timestamps();
            });

            return;
        }

        $this->addColumn('users', 'visibility', fn (Blueprint $table) => $table->string('visibility')->default('offline'));
        $this->addColumn('users', 'last_seen_at', fn (Blueprint $table) => $table->timestamp('last_seen_at')->nullable());
        $this->addColumn('users', 'reset_token', fn (Blueprint $table) => $table->string('reset_token')->nullable());
        $this->addColumn('users', 'reset_token_expires', fn (Blueprint $table) => $table->timestamp('reset_token_expires')->nullable());
    }

    private function apiTokens(): void
    {
        if (Schema::hasTable('api_tokens')) {
            return;
        }

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('token', 128)->unique();
            $table->string('type')->default('access');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });
    }

    private function systemSettings(): void
    {
        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->integer('id')->primary();
                $table->string('app_title')->default('Stuckbema Leveransdokument');
                $table->string('company_name')->default('Stuckbema');
                $table->string('delivery_title')->default('Leveransdokument');
                $table->string('support_email')->nullable();
                $table->string('support_phone')->nullable();
                $table->string('order_number_prefix')->default('LEV');
                $table->boolean('allow_push_notifications')->default(true);
                $table->text('admin_message')->nullable();
                $table->string('updated_by')->nullable();
                $table->timestamps();
            });

            return;
        }

        $this->addColumn('system_settings', 'created_at', fn (Blueprint $table) => $table->timestamp('created_at')->nullable());
    }

    private function people(): void
    {
        if (! Schema::hasTable('people')) {
            Schema::create('people', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('user_id')->nullable();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('image_path')->nullable();
                $table->string('photo_url')->nullable();
                $table->string('role')->default('worker');
                $table->boolean('active')->default(true);
                $table->string('source')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });

            return;
        }

        $this->addColumn('people', 'source', fn (Blueprint $table) => $table->string('source')->nullable());
        $this->addColumn('people', 'metadata', fn (Blueprint $table) => $table->json('metadata')->nullable());
    }

    private function orders(): void
    {
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('adress');
                $table->string('tele')->nullable();
                $table->string('mottagare');
                $table->string('order_number')->nullable()->index();
                $table->string('customer_name')->nullable();
                $table->string('customer_phone')->nullable();
                $table->string('customer_email')->nullable();
                $table->string('recipient_name')->nullable();
                $table->string('recipient_phone')->nullable();
                $table->string('recipient_email')->nullable();
                $table->text('delivery_address')->nullable();
                $table->date('desired_delivery_date')->nullable();
                $table->time('desired_delivery_time')->nullable();
                $table->string('assigned_driver_id')->nullable()->index();
                $table->string('driver_id')->nullable()->index();
                $table->string('driver_name')->nullable();
                $table->string('status')->default('created')->index();
                $table->string('priority')->nullable();
                $table->text('notes')->nullable();
                $table->text('internal_comment')->nullable();
                $table->boolean('tracking_enabled')->default(false);
                $table->string('tracking_token')->nullable()->index();
                $table->string('tracking_url')->nullable();
                $table->string('tracking_session_id')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('last_stopped_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->string('delivered_by')->nullable();
                $table->text('delivered_note')->nullable();
                $table->json('current_location')->nullable();
                $table->string('external_work_order_number')->nullable()->index();
                $table->json('original_work_order_snapshot')->nullable();
                $table->timestamp('sms_sent_at')->nullable();
                $table->string('sms_status')->nullable();
                $table->text('sms_error')->nullable();
                $table->string('created_by')->nullable();
                $table->string('updated_by')->nullable();
                $table->timestamps();
            });

            return;
        }

        foreach ([
            'order_number' => fn (Blueprint $table) => $table->string('order_number')->nullable()->index(),
            'customer_name' => fn (Blueprint $table) => $table->string('customer_name')->nullable(),
            'customer_phone' => fn (Blueprint $table) => $table->string('customer_phone')->nullable(),
            'customer_email' => fn (Blueprint $table) => $table->string('customer_email')->nullable(),
            'recipient_name' => fn (Blueprint $table) => $table->string('recipient_name')->nullable(),
            'recipient_phone' => fn (Blueprint $table) => $table->string('recipient_phone')->nullable(),
            'delivery_address' => fn (Blueprint $table) => $table->text('delivery_address')->nullable(),
            'assigned_driver_id' => fn (Blueprint $table) => $table->string('assigned_driver_id')->nullable()->index(),
            'driver_id' => fn (Blueprint $table) => $table->string('driver_id')->nullable()->index(),
            'driver_name' => fn (Blueprint $table) => $table->string('driver_name')->nullable(),
            'delivered_by' => fn (Blueprint $table) => $table->string('delivered_by')->nullable(),
            'delivered_note' => fn (Blueprint $table) => $table->text('delivered_note')->nullable(),
        ] as $column => $definition) {
            $this->addColumn('orders', $column, $definition);
        }

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

    private function orderItems(): void
    {
        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table) {
                $table->id();
                $table->string('order_id')->index();
                $table->string('artikel');
                $table->string('article')->nullable();
                $table->string('benamning')->nullable();
                $table->string('description')->nullable();
                $table->string('antal');
                $table->string('quantity')->nullable();
                $table->integer('sort_order')->default(0);
                $table->string('work_order_number')->nullable()->index();
                $table->string('delivered_antal')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
            });

            return;
        }

        foreach ([
            'article' => fn (Blueprint $table) => $table->string('article')->nullable(),
            'benamning' => fn (Blueprint $table) => $table->string('benamning')->nullable(),
            'description' => fn (Blueprint $table) => $table->string('description')->nullable(),
            'quantity' => fn (Blueprint $table) => $table->string('quantity')->nullable(),
            'payload' => fn (Blueprint $table) => $table->json('payload')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ] as $column => $definition) {
            $this->addColumn('order_items', $column, $definition);
        }

        DB::statement("
            update order_items set
                article = coalesce(article, artikel),
                quantity = coalesce(quantity, antal),
                description = coalesce(description, artikel),
                created_at = coalesce(created_at, now()),
                updated_at = coalesce(updated_at, now())
        ");
    }

    private function externalWorkOrders(): void
    {
        if (! Schema::hasTable('external_work_orders')) {
            Schema::create('external_work_orders', function (Blueprint $table) {
                $table->string('work_order_number')->primary();
                $table->string('source')->nullable();
                $table->string('recipient_name')->nullable();
                $table->string('recipient_phone')->nullable();
                $table->text('delivery_address')->nullable();
                $table->json('original_payload');
                $table->string('status')->default('open');
                $table->timestamp('received_at')->nullable();
                $table->timestamps();
            });

            return;
        }

        $this->addColumn('external_work_orders', 'created_at', fn (Blueprint $table) => $table->timestamp('created_at')->nullable());
        DB::statement("update external_work_orders set created_at = coalesce(created_at, received_at, updated_at, now())");
    }

    private function workOrderDeliveryEvents(): void
    {
        if (! Schema::hasTable('work_order_delivery_events')) {
            Schema::create('work_order_delivery_events', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('work_order_number')->index();
                $table->string('order_id')->index();
                $table->integer('item_index')->default(0);
                $table->string('artikel');
                $table->string('delivered_antal');
                $table->timestamp('delivered_at')->useCurrent();
                $table->string('delivered_by')->nullable();
                $table->timestamps();
            });

            return;
        }

        $this->addColumn('work_order_delivery_events', 'updated_at', fn (Blueprint $table) => $table->timestamp('updated_at')->nullable());
    }

    private function trackingLinks(): void
    {
        if (Schema::hasTable('tracking_links')) {
            return;
        }

        Schema::create('tracking_links', function (Blueprint $table) {
            $table->string('token')->primary();
            $table->string('order_id')->index();
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    private function pushSubscriptions(): void
    {
        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('user_id')->index();
                $table->text('endpoint')->unique();
                $table->text('p256dh')->default('');
                $table->text('auth')->default('');
                $table->string('platform')->default('web');
                $table->string('provider')->default('webpush');
                $table->text('user_agent')->nullable();
                $table->string('permission')->nullable();
                $table->string('device_name')->nullable();
                $table->string('app_version')->nullable();
                $table->json('payload')->nullable();
                $table->boolean('enabled')->default(true);
                $table->integer('failure_count')->default(0);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });

            return;
        }

        foreach ([
            'platform' => fn (Blueprint $table) => $table->string('platform')->default('web'),
            'user_agent' => fn (Blueprint $table) => $table->text('user_agent')->nullable(),
            'permission' => fn (Blueprint $table) => $table->string('permission')->nullable(),
            'provider' => fn (Blueprint $table) => $table->string('provider')->default('webpush'),
            'device_name' => fn (Blueprint $table) => $table->string('device_name')->nullable(),
            'app_version' => fn (Blueprint $table) => $table->string('app_version')->nullable(),
            'enabled' => fn (Blueprint $table) => $table->boolean('enabled')->default(true),
            'last_seen_at' => fn (Blueprint $table) => $table->timestamp('last_seen_at')->nullable(),
            'last_success_at' => fn (Blueprint $table) => $table->timestamp('last_success_at')->nullable(),
            'last_failure_at' => fn (Blueprint $table) => $table->timestamp('last_failure_at')->nullable(),
            'failure_count' => fn (Blueprint $table) => $table->integer('failure_count')->default(0),
            'revoked_at' => fn (Blueprint $table) => $table->timestamp('revoked_at')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
            'payload' => fn (Blueprint $table) => $table->json('payload')->nullable(),
        ] as $column => $definition) {
            $this->addColumn('push_subscriptions', $column, $definition);
        }
    }

    public function down(): void
    {
        foreach ([
            'api_tokens',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
