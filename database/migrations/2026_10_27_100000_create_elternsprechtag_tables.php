<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elternsprechtage und die Terminpläne der Lehrer.
 *
 * Klassen sind die Klassen-Schlüssel aus dem Linear-Abgleich (`07a` aus der
 * Rolle `linear-klasse-07a-lehrer`) – als JSON am Sprechtag, keine eigene
 * Tabelle, weil sie nur dort gebraucht werden.
 *
 * Ein Plan je Lehrer und Sprechtag. Eine Zeile in `elternsprechtag_plan_tage`
 * heißt „an diesem Tag dabei" – mit eigener Start-/Endzeit und Pausen (JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('elternsprechtage')) {
            Schema::create('elternsprechtage', function (Blueprint $table) {
                $table->id();
                $table->string('titel');
                $table->json('klassen')->nullable();
                $table->date('lehrer_frist')->nullable();
                $table->text('hinweis')->nullable();          // Text für die Eltern-Info
                $table->timestamp('info_versendet_am')->nullable();
                $table->unsignedInteger('info_empfaenger')->nullable();
                $table->foreignId('angelegt_von')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('elternsprechtag_tage')) {
            Schema::create('elternsprechtag_tage', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sprechtag_id')->constrained('elternsprechtage')->cascadeOnDelete();
                $table->date('datum');
                $table->string('standard_von', 5)->nullable();  // Vorschlag für die Lehrer
                $table->string('standard_bis', 5)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('elternsprechtag_plaene')) {
            Schema::create('elternsprechtag_plaene', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sprechtag_id')->constrained('elternsprechtage')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedSmallInteger('slot_minuten')->default(10);
                $table->unsignedSmallInteger('vorbereitung_minuten')->default(0);
                $table->string('raum')->nullable();
                $table->text('bemerkung')->nullable();
                $table->timestamp('abgeschlossen_am')->nullable();
                $table->timestamps();
                $table->unique(['sprechtag_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('elternsprechtag_plan_tage')) {
            Schema::create('elternsprechtag_plan_tage', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('elternsprechtag_plaene')->cascadeOnDelete();
                $table->foreignId('tag_id')->constrained('elternsprechtag_tage')->cascadeOnDelete();
                $table->string('von', 5);
                $table->string('bis', 5);
                $table->json('pausen')->nullable();           // [{"von":"16:00","bis":"16:15"}, …]
                $table->timestamps();
                $table->unique(['plan_id', 'tag_id']);
            });
        }

        if (! Schema::hasTable('elternsprechtag_wuensche')) {
            Schema::create('elternsprechtag_wuensche', function (Blueprint $table) {
                $table->id();
                $table->foreignId('plan_id')->constrained('elternsprechtag_plaene')->cascadeOnDelete();
                $table->foreignId('kind_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('notiz')->nullable();
                $table->timestamps();
                $table->unique(['plan_id', 'kind_user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('elternsprechtag_wuensche');
        Schema::dropIfExists('elternsprechtag_plan_tage');
        Schema::dropIfExists('elternsprechtag_plaene');
        Schema::dropIfExists('elternsprechtag_tage');
        Schema::dropIfExists('elternsprechtage');
    }
};
