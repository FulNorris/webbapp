<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private string $sequenceName = 'internal_work_order_number_seq';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('arbetsordrar')) {
            return;
        }

        DB::statement("create sequence if not exists {$this->sequenceName}");

        $nextNumber = $this->maxInternalWorkOrderNumber() + 1;
        $nextNumber = max(1, $nextNumber);

        DB::select('select setval(?::regclass, ?::bigint, false)', [
            $this->sequenceName,
            $nextNumber,
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("drop sequence if exists {$this->sequenceName}");
    }

    private function maxInternalWorkOrderNumber(): int
    {
        $orderReferenceMax = 0;
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'internal_work_order_number')) {
            $orderReferenceMax = (int) DB::table('orders')
                ->whereNotNull('internal_work_order_number')
                ->max('internal_work_order_number');
        }

        $rawTextMax = 0;
        if (Schema::hasColumn('arbetsordrar', 'raw_text')) {
            $rawTextMax = (int) DB::table('arbetsordrar')
                ->where('raw_text', 'like', 'Intern arbetsorder skapad automatiskt från leverans %')
                ->max('arbetsorder_nr');
        }

        return max($orderReferenceMax, $rawTextMax, 0);
    }
};
