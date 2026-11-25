<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telefontermin: gesetzt = das Gespräch findet am Telefon statt, die Lehrkraft
 * ruft unter dieser Nummer an. Leer = vor Ort.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('elternsprechtag_termine', 'telefon')) {
            Schema::table('elternsprechtag_termine', function (Blueprint $table) {
                $table->string('telefon', 40)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtag_termine', 'telefon')) {
            Schema::table('elternsprechtag_termine', function (Blueprint $table) {
                $table->dropColumn('telefon');
            });
        }
    }
};
