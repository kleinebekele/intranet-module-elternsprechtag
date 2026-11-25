<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Markierungen im einheitlichen Raster: je Uhrzeit „pause", „gesperrt" oder
 * „backup" (Reserve), z. B. {"14:00": "pause"}. Nicht aufgeführt = frei.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('elternsprechtag_plan_tage', 'markierungen')) {
            Schema::table('elternsprechtag_plan_tage', function (Blueprint $table) {
                $table->json('markierungen')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtag_plan_tage', 'markierungen')) {
            Schema::table('elternsprechtag_plan_tage', function (Blueprint $table) {
                $table->dropColumn('markierungen');
            });
        }
    }
};
