<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geblockte Zeiten im Terminplan einer Lehrkraft: Eltern können sie nicht
 * buchen, nur die Verwaltung (z. B. für ein Gespräch nach einem Anruf).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('elternsprechtag_blockaden')) {
            return;
        }

        Schema::create('elternsprechtag_blockaden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('elternsprechtag_plaene')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('elternsprechtag_tage')->cascadeOnDelete();
            $table->string('von', 5);
            $table->string('grund', 255)->nullable();
            $table->foreignId('erstellt_von')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'tag_id', 'von']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elternsprechtag_blockaden');
    }
};
