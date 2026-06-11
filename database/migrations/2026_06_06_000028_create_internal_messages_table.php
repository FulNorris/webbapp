<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('internal_messages')) {
            Schema::create('internal_messages', function (Blueprint $table) {
                $table->id();
                $table->string('sender_id')->nullable();
                $table->string('recipient_id');
                $table->string('order_id')->nullable();
                $table->string('subject');
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamp('sender_deleted_at')->nullable();
                $table->timestamp('recipient_deleted_at')->nullable();
                $table->timestamps();

                $table->index(['recipient_id', 'read_at', 'created_at']);
                $table->index(['sender_id', 'created_at']);
                $table->index(['order_id']);
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'image_path')) {
                    $table->string('image_path')->nullable();
                }
                if (! Schema::hasColumn('users', 'photo_url')) {
                    $table->string('photo_url')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_messages');
    }
};
