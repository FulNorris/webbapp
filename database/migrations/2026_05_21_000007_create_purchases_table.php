<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('purchases')) {
            Schema::create('purchases', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('quantity')->default(1);
                $table->string('item_name');
                $table->string('store_name')->nullable();
                $table->string('delivery_address')->nullable();
                $table->string('recipient_name')->nullable();
                $table->string('status')->default('planned');
                $table->text('notes')->nullable();
                $table->timestamps();
            });

            return;
        }

        foreach ([
            'quantity' => fn (Blueprint $table) => $table->unsignedInteger('quantity')->default(1),
            'item_name' => fn (Blueprint $table) => $table->string('item_name')->default('Inköp'),
            'store_name' => fn (Blueprint $table) => $table->string('store_name')->nullable(),
            'delivery_address' => fn (Blueprint $table) => $table->string('delivery_address')->nullable(),
            'recipient_name' => fn (Blueprint $table) => $table->string('recipient_name')->nullable(),
            'status' => fn (Blueprint $table) => $table->string('status')->default('planned'),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
            'created_at' => fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn('purchases', $column)) {
                Schema::table('purchases', fn (Blueprint $table) => $definition($table));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
