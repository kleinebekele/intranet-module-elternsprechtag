<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raum am Termin – für gemeinsame Gespräche: festgelegt auf einen der Räume der
 * beteiligten Lehrkräfte, umschaltbar. Leer = Raum aus dem Plan der Lehrkraft.
 * Bestehende gemeinsame Termine bekommen den ersten eingetragenen Raum.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('elternsprechtag_termine', 'raum')) {
            Schema::table('elternsprechtag_termine', function (Blueprint $table) {
                $table->string('raum')->nullable();
            });
        }
        // Von Hand abweichend gesetzt: bleibt, wenn sich die Raumwahl der Klasse ändert.
        if (! Schema::hasColumn('elternsprechtag_termine', 'raum_abweichend')) {
            Schema::table('elternsprechtag_termine', function (Blueprint $table) {
                $table->boolean('raum_abweichend')->default(false);
            });
        }

        $gruppen = DB::table('elternsprechtag_termine as t')
            ->join('elternsprechtag_plaene as p', 'p.id', '=', 't.plan_id')
            ->whereNotNull('t.gruppe')->whereNull('t.raum')
            ->orderBy('t.plan_id')
            ->get(['t.gruppe', 'p.raum'])
            ->groupBy('gruppe');
        foreach ($gruppen as $gruppe => $zeilen) {
            $raum = $zeilen->pluck('raum')->first(fn ($r) => filled($r));
            if ($raum !== null) {
                DB::table('elternsprechtag_termine')->where('gruppe', $gruppe)->update(['raum' => $raum]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtag_termine', 'raum')) {
            Schema::table('elternsprechtag_termine', function (Blueprint $table) {
                $table->dropColumn(['raum', 'raum_abweichend']);
            });
        }
    }
};
