<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gemeinsame Gespräche einer Klasse finden im Raum dieser Klassenlehrkraft
 * statt. Leer = der erste eingetragene Raum.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('elternsprechtag_klassenteams', 'raum_user_id')) {
            Schema::table('elternsprechtag_klassenteams', function (Blueprint $table) {
                $table->foreignId('raum_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtag_klassenteams', 'raum_user_id')) {
            Schema::table('elternsprechtag_klassenteams', function (Blueprint $table) {
                $table->dropConstrainedForeignId('raum_user_id');
            });
        }
    }
};
