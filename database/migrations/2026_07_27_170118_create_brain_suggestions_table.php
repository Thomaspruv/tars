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
        Schema::create('brain_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brain_document_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('target')->nullable();
            $table->json('frontmatter_patch')->nullable();
            $table->text('merged_content')->nullable();
            $table->string('confidence');
            $table->text('reason');
            $table->string('status')->default('pending')->index();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('created');
            $table->timestamp('deprioritized_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brain_suggestions');
    }
};
