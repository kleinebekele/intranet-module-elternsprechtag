<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ein Lehrer kann seine Teilnahme an einem Sprechtag absagen (mit Grund für die Verwaltung). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('elternsprechtag_plaene', 'abgesagt_am')) {
            Schema::table('elternsprechtag_plaene', function (Blueprint $table) {
                $table->timestamp('abgesagt_am')->nullable()->after('abgeschlossen_am');
                $table->string('absage_grund', 500)->nullable()->after('abgesagt_am');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtag_plaene', 'abgesagt_am')) {
            Schema::table('elternsprechtag_plaene', function (Blueprint $table) {
                $table->dropColumn(['abgesagt_am', 'absage_grund']);
            });
        }
    }
};
