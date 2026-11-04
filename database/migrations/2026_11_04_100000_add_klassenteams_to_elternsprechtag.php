<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gemeinsame Gespräche mehrerer Klassenlehrer einer Klasse: je Sprechtag und
 * Klasse, welche Gespräche gemeinsam geführt werden (`wunsch` oder `alle`),
 * am Termin die Gruppe, die die Termine der beteiligten Lehrer verbindet, und
 * `allein` für einen Termin, der gemeinsam gedacht war, aber nur bei einem
 * Klassenlehrer frei war.
 *
 * Dazu der Vorsprung persönlich eingeladener Eltern: Sie bekommen die Info und
 * dürfen buchen, alle anderen erst `vorsprung_minuten` später.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('elternsprechtag_klassenteams')) {
            Schema::create('elternsprechtag_klassenteams', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sprechtag_id')->constrained('elternsprechtage')->cascadeOnDelete();
                $table->string('klasse', 100);
                $table->string('modus', 20)->default('wunsch');
                $table->timestamps();
                $table->unique(['sprechtag_id', 'klasse']);
            });
        }

        if (! Schema::hasColumn('elternsprechtag_termine', 'gruppe')) {
            Schema::table('elternsprechtag_termine', function (Blueprint $table) {
                $table->string('gruppe', 36)->nullable()->after('eltern_user_id')->index();
                $table->boolean('allein')->default(false)->after('gruppe');
            });
        }

        if (! Schema::hasColumn('elternsprechtage', 'vorsprung_minuten')) {
            Schema::table('elternsprechtage', function (Blueprint $table) {
                $table->unsignedSmallInteger('vorsprung_minuten')->default(60)->after('info_versendet_am');
                $table->timestamp('info_rest_versendet_am')->nullable()->after('vorsprung_minuten');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('elternsprechtage', 'vorsprung_minuten')) {
            Schema::table('elternsprechtage', function (Blueprint $table) {
                $table->dropColumn(['vorsprung_minuten', 'info_rest_versendet_am']);
            });
        }
        if (Schema::hasColumn('elternsprechtag_termine', 'gruppe')) {
            Schema::table('elternsprechtag_termine', function (Blueprint $table) {
                $table->dropIndex(['gruppe']);
                $table->dropColumn(['gruppe', 'allein']);
            });
        }
        Schema::dropIfExists('elternsprechtag_klassenteams');
    }
};
