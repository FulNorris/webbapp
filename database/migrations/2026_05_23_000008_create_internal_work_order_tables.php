<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('arbetsordrar')) {
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
        }

        if (! Schema::hasTable('arbetsorder_resurser')) {
            Schema::create('arbetsorder_resurser', function (Blueprint $table) {
                $table->id();
                $table->foreignId('arbetsorder_id')->constrained('arbetsordrar')->cascadeOnDelete();
                $table->string('namn');
                $table->string('telefon')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('arbetsorder_rader')) {
            Schema::create('arbetsorder_rader', function (Blueprint $table) {
                $table->id();
                $table->foreignId('arbetsorder_id')->constrained('arbetsordrar')->cascadeOnDelete();
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
        }

        $this->addOrderItemColumns();
        $this->backfillOrderItemWorkOrderColumns();
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table) {
                foreach (['arbetsorder_id', 'arbetsorder_rad_id'] as $column) {
                    if (Schema::hasColumn('order_items', $column)) {
                        try {
                            $table->dropForeign([$column]);
                        } catch (\Throwable) {
                            // The constraint may not exist on SQLite or older installs.
                        }
                    }
                }
            });
        }

        foreach ([
            'arbetsorder_nr',
            'arbetsorder_id',
            'arbetsorder_rad_id',
            'artikel_normalized',
            'enhet',
            'levererat_antal',
            'match_status',
            'match_warning',
        ] as $column) {
            if (Schema::hasColumn('order_items', $column)) {
                Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        Schema::dropIfExists('arbetsorder_rader');
        Schema::dropIfExists('arbetsorder_resurser');
        Schema::dropIfExists('arbetsordrar');
    }

    private function addOrderItemColumns(): void
    {
        foreach ([
            'arbetsorder_nr' => fn (Blueprint $table) => $table->unsignedInteger('arbetsorder_nr')->nullable()->index(),
            'arbetsorder_id' => fn (Blueprint $table) => $table->foreignId('arbetsorder_id')->nullable()->constrained('arbetsordrar')->nullOnDelete(),
            'arbetsorder_rad_id' => fn (Blueprint $table) => $table->foreignId('arbetsorder_rad_id')->nullable()->constrained('arbetsorder_rader')->nullOnDelete(),
            'artikel_normalized' => fn (Blueprint $table) => $table->string('artikel_normalized')->nullable()->index(),
            'enhet' => fn (Blueprint $table) => $table->string('enhet')->nullable(),
            'levererat_antal' => fn (Blueprint $table) => $table->decimal('levererat_antal', 12, 3)->nullable(),
            'match_status' => fn (Blueprint $table) => $table->string('match_status')->nullable()->index(),
            'match_warning' => fn (Blueprint $table) => $table->text('match_warning')->nullable(),
        ] as $column => $definition) {
            if (! Schema::hasColumn('order_items', $column)) {
                Schema::table('order_items', $definition);
            }
        }
    }

    private function backfillOrderItemWorkOrderColumns(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::table('order_items')
                ->whereNull('arbetsorder_nr')
                ->whereNotNull('work_order_number')
                ->whereRaw("trim(work_order_number) ~ '^[0-9]+$'")
                ->update([
                    'arbetsorder_nr' => DB::raw('work_order_number::integer'),
                ]);

            return;
        }

        DB::table('order_items')
            ->whereNull('arbetsorder_nr')
            ->whereNotNull('work_order_number')
            ->orderBy('id')
            ->chunkById(200, function ($items) {
                foreach ($items as $item) {
                    $number = trim((string) $item->work_order_number);
                    if (! ctype_digit($number)) {
                        continue;
                    }

                    DB::table('order_items')->where('id', $item->id)->update([
                        'arbetsorder_nr' => (int) $number,
                    ]);
                }
            });
    }
};
