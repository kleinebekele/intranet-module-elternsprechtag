<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gebuchte Gesprächstermine: ein Slot im Plan eines Lehrers, für ein Kind.
 * Je Lehrer und Kind höchstens ein Termin, je Slot höchstens eine Buchung.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('elternsprechtag_termine')) {
            return;
        }

        Schema::create('elternsprechtag_termine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('elternsprechtag_plaene')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('elternsprechtag_tage')->cascadeOnDelete();
            $table->string('von', 5);
            $table->string('bis', 5);
            $table->foreignId('kind_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('eltern_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('gebucht_von')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'tag_id', 'von']);
            $table->unique(['plan_id', 'kind_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elternsprechtag_termine');
    }
};
