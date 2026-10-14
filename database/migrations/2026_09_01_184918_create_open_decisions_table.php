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
        Schema::create('open_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('context')->nullable();
            $table->string('status')->default('open')->index();
            $table->foreignId('goal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_date')->nullable()->index();
            $table->unsignedBigInteger('chosen_option_id')->nullable();
            $table->foreignId('decision_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('open_decisions');
    }
};
