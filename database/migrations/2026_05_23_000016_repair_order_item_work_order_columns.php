<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('order_items')) {
            return;
        }

        $this->addColumnIfMissing('arbetsorder_nr', fn (Blueprint $table) => $table->unsignedInteger('arbetsorder_nr')->nullable()->index());
        $this->addColumnIfMissing('arbetsorder_id', fn (Blueprint $table) => $table->unsignedBigInteger('arbetsorder_id')->nullable()->index());
        $this->addColumnIfMissing('arbetsorder_rad_id', fn (Blueprint $table) => $table->unsignedBigInteger('arbetsorder_rad_id')->nullable()->index());
        $this->addColumnIfMissing('artikel_normalized', fn (Blueprint $table) => $table->string('artikel_normalized')->nullable()->index());
        $this->addColumnIfMissing('enhet', fn (Blueprint $table) => $table->string('enhet')->nullable());
        $this->addColumnIfMissing('levererat_antal', fn (Blueprint $table) => $table->decimal('levererat_antal', 12, 3)->nullable());
        $this->addColumnIfMissing('delivered_antal', fn (Blueprint $table) => $table->decimal('delivered_antal', 12, 3)->nullable());
        $this->addColumnIfMissing('delivered_at', fn (Blueprint $table) => $table->timestamp('delivered_at')->nullable());
        $this->addColumnIfMissing('match_status', fn (Blueprint $table) => $table->string('match_status')->nullable()->index());
        $this->addColumnIfMissing('match_warning', fn (Blueprint $table) => $table->text('match_warning')->nullable());

        if (DB::getDriverName() === 'pgsql') {
            DB::table('order_items')
                ->whereNull('arbetsorder_nr')
                ->whereNotNull('work_order_number')
                ->whereRaw("trim(work_order_number) ~ '^[0-9]+$'")
                ->update(['arbetsorder_nr' => DB::raw('work_order_number::integer')]);

            DB::table('order_items')
                ->whereNull('artikel_normalized')
                ->whereNotNull('artikel')
                ->update(['artikel_normalized' => DB::raw("regexp_replace(upper(artikel), '[^[:alnum:]ÅÄÖ]', '', 'g')")]);
        }
    }

    public function down(): void
    {
        foreach ([
            'arbetsorder_nr',
            'arbetsorder_id',
            'arbetsorder_rad_id',
            'artikel_normalized',
            'enhet',
            'levererat_antal',
            'delivered_antal',
            'delivered_at',
            'match_status',
            'match_warning',
        ] as $column) {
            if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', $column)) {
                Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    private function addColumnIfMissing(string $columnName, callable $definition): void
    {
        if (! Schema::hasColumn('order_items', $columnName)) {
            Schema::table('order_items', $definition);
        }
    }
};
