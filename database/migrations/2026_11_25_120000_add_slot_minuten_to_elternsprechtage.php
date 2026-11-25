<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einheitliche Gesprächsdauer je Sprechtag (Minuten). Gesetzt = alle Lehrkräfte
 * haben dasselbe Raster: Beginn und Ende der Tage plus diese Dauer, ohne eigene
 * Vorbereitungszeit. Leer = wie bisher, jede Lehrkraft wählt selbst.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('elternsprechtage', 'slot_minuten')) {
            Schema::table('elternsprechtage', function (Blueprint $table) {
                $table->unsignedSmallInteger('slot_minuten')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtage', 'slot_minuten')) {
            Schema::table('elternsprechtage', function (Blueprint $table) {
                $table->dropColumn('slot_minuten');
            });
        }
    }
};
