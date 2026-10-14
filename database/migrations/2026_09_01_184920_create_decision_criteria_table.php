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
        Schema::create('decision_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('open_decision_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedTinyInteger('weight')->default(3);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('decision_criteria');
    }
};
