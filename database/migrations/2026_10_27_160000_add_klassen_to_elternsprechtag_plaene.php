<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klassen, für die ein Lehrer an diesem Sprechtag zur Verfügung steht.
 * NULL = noch nicht gewählt → Vorauswahl aus den Linear-Lehrerrollen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('elternsprechtag_plaene', 'klassen')) {
            Schema::table('elternsprechtag_plaene', function (Blueprint $table) {
                $table->json('klassen')->nullable()->after('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtag_plaene', 'klassen')) {
            Schema::table('elternsprechtag_plaene', function (Blueprint $table) {
                $table->dropColumn('klassen');
            });
        }
    }
};
