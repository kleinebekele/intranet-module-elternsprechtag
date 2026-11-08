<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terminliste per Mail an die Lehrkräfte:
 * - `elternsprechtag_plaene.voll_gemeldet_am`: Plan war voll, Liste ist raus.
 *   Wird wieder leer, sobald ein Termin frei wird – beim nächsten Vollwerden
 *   geht die Liste erneut raus.
 * - `elternsprechtage.eltern_frist`: bis wann Eltern buchen (leer = Vorabend
 *   des ersten Tages, 18 Uhr).
 * - `elternsprechtage.schluss_gemeldet_am`: Frist der Eltern ist durch, alle
 *   übrigen Lehrkräfte haben ihre Liste bekommen.
 * - `elternsprechtag_besuche.keine_zeit_am` / `keine_zeit_bemerkung`: Eltern
 *   melden, dass sie diesmal keinen Termin wahrnehmen können (das Sekretariat
 *   muss dann nicht nachtelefonieren).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('elternsprechtag_plaene', 'voll_gemeldet_am')) {
            Schema::table('elternsprechtag_plaene', function (Blueprint $table) {
                $table->timestamp('voll_gemeldet_am')->nullable();
            });
        }
        if (! Schema::hasColumn('elternsprechtage', 'schluss_gemeldet_am')) {
            Schema::table('elternsprechtage', function (Blueprint $table) {
                $table->timestamp('schluss_gemeldet_am')->nullable();
            });
        }
        if (! Schema::hasColumn('elternsprechtage', 'eltern_frist')) {
            Schema::table('elternsprechtage', function (Blueprint $table) {
                $table->dateTime('eltern_frist')->nullable();
            });
        }
        if (! Schema::hasColumn('elternsprechtag_besuche', 'keine_zeit_am')) {
            Schema::table('elternsprechtag_besuche', function (Blueprint $table) {
                $table->timestamp('keine_zeit_am')->nullable();
                $table->string('keine_zeit_bemerkung', 1000)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtag_plaene', 'voll_gemeldet_am')) {
            Schema::table('elternsprechtag_plaene', fn (Blueprint $table) => $table->dropColumn('voll_gemeldet_am'));
        }
        if (Schema::hasColumn('elternsprechtage', 'schluss_gemeldet_am')) {
            Schema::table('elternsprechtage', fn (Blueprint $table) => $table->dropColumn('schluss_gemeldet_am'));
        }
        if (Schema::hasColumn('elternsprechtage', 'eltern_frist')) {
            Schema::table('elternsprechtage', fn (Blueprint $table) => $table->dropColumn('eltern_frist'));
        }
        if (Schema::hasColumn('elternsprechtag_besuche', 'keine_zeit_am')) {
            Schema::table('elternsprechtag_besuche', fn (Blueprint $table) => $table->dropColumn(['keine_zeit_am', 'keine_zeit_bemerkung']));
        }
    }
};
