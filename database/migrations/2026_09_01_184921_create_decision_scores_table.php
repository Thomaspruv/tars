<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('decision_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('decision_criterion_id')->constrained('decision_criteria')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamps();

            // Nom explicite : le nom auto-généré ferait 65 caractères et MySQL
            // en refuse plus de 64 (SQLite, lui, laisserait passer).
            $table->unique(['decision_option_id', 'decision_criterion_id'], 'decision_scores_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('decision_scores');
    }
};
