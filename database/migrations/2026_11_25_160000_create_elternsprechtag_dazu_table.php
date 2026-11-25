<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eine Lehrkraft (meist die Klassenlehrkraft) kommt zu einem Termin einer
 * Kollegin dazu. Fällt der Termin weg, fällt auch das Dazukommen weg.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('elternsprechtag_dazu')) {
            return;
        }
        Schema::create('elternsprechtag_dazu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('termin_id')->constrained('elternsprechtag_termine')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['termin_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elternsprechtag_dazu');
    }
};
