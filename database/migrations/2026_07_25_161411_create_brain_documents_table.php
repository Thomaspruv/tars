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
        Schema::create('brain_documents', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('title')->nullable();
            $table->json('frontmatter')->nullable();
            $table->longText('content')->nullable();
            $table->string('hash')->index();
            $table->dateTime('mtime')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brain_documents');
    }
};
