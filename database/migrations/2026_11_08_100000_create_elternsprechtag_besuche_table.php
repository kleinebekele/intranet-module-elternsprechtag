<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wer hat die Terminseite eines Sprechtags selbst geöffnet? Zählt nur den
 * eigenen Aufruf der Eltern, nicht das Handeln der Verwaltung (?eltern=).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('elternsprechtag_besuche')) {
            return;
        }

        Schema::create('elternsprechtag_besuche', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sprechtag_id')->constrained('elternsprechtage')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('erster_besuch_am')->nullable();
            $table->timestamp('letzter_besuch_am')->nullable();

            $table->unique(['sprechtag_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elternsprechtag_besuche');
    }
};
