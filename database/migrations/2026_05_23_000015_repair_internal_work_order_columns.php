<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('arbetsordrar')) {
            $this->addColumnIfMissing('arbetsordrar', 'utskriftsdatum', fn (Blueprint $table) => $table->date('utskriftsdatum')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'handlaggare', fn (Blueprint $table) => $table->string('handlaggare')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'fardig_datum', fn (Blueprint $table) => $table->date('fardig_datum')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'ordernr', fn (Blueprint $table) => $table->unsignedInteger('ordernr')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'projekt', fn (Blueprint $table) => $table->unsignedInteger('projekt')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'startdatum', fn (Blueprint $table) => $table->date('startdatum')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'startdatum_text', fn (Blueprint $table) => $table->string('startdatum_text')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'kontaktperson', fn (Blueprint $table) => $table->string('kontaktperson')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'telefon', fn (Blueprint $table) => $table->string('telefon')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'arbetsplats', fn (Blueprint $table) => $table->string('arbetsplats')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'postadress', fn (Blueprint $table) => $table->string('postadress')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'arbetsbeskrivning_raw', fn (Blueprint $table) => $table->text('arbetsbeskrivning_raw')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'handskrivet_raw', fn (Blueprint $table) => $table->text('handskrivet_raw')->nullable());
            $this->addColumnIfMissing('arbetsordrar', 'is_kopia', fn (Blueprint $table) => $table->boolean('is_kopia')->default(false));
            $this->addColumnIfMissing('arbetsordrar', 'raw_text', fn (Blueprint $table) => $table->text('raw_text')->nullable());
        }

        if (Schema::hasTable('arbetsorder_rader')) {
            $this->addColumnIfMissing('arbetsorder_rader', 'kommentar', fn (Blueprint $table) => $table->text('kommentar')->nullable());
        }
    }

    public function down(): void
    {
        foreach ([
            'utskriftsdatum',
            'handlaggare',
            'fardig_datum',
            'ordernr',
            'projekt',
            'startdatum',
            'startdatum_text',
            'kontaktperson',
            'telefon',
            'arbetsplats',
            'postadress',
            'arbetsbeskrivning_raw',
            'handskrivet_raw',
            'is_kopia',
            'raw_text',
        ] as $column) {
            if (Schema::hasTable('arbetsordrar') && Schema::hasColumn('arbetsordrar', $column)) {
                Schema::table('arbetsordrar', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        if (Schema::hasTable('arbetsorder_rader') && Schema::hasColumn('arbetsorder_rader', 'kommentar')) {
            Schema::table('arbetsorder_rader', fn (Blueprint $table) => $table->dropColumn('kommentar'));
        }
    }

    private function addColumnIfMissing(string $tableName, string $columnName, callable $definition): void
    {
        if (! Schema::hasColumn($tableName, $columnName)) {
            Schema::table($tableName, $definition);
        }
    }
};
