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
        Schema::create('inbox_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_item_id')->constrained()->cascadeOnDelete();
            $table->string('suggested_type');
            $table->json('suggested_fields');
            $table->string('confidence');
            $table->text('reason');
            $table->string('status')->default('pending')->index();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('created');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_suggestions');
    }
};
