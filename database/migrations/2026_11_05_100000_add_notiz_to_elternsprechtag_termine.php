<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notiz der Lehrkraft zu einem gebuchten Termin (Terminliste → Auge).
 * Sehen nur Lehrkraft und Verwaltung, nie die Eltern.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('elternsprechtag_termine', 'notiz')) {
            Schema::table('elternsprechtag_termine', function (Blueprint $table) {
                $table->text('notiz')->nullable()->after('allein');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtag_termine', 'notiz')) {
            Schema::table('elternsprechtag_termine', function (Blueprint $table) {
                $table->dropColumn('notiz');
            });
        }
    }
};
