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
        Schema::create('brain_document_anchors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brain_document_id')->constrained()->cascadeOnDelete();
            $table->morphs('anchorable');
            $table->timestamps();

            $table->unique(['brain_document_id', 'anchorable_type', 'anchorable_id'], 'brain_document_anchors_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brain_document_anchors');
    }
};
