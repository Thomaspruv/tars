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
        Schema::create('mcp_calls', function (Blueprint $table) {
            $table->id();
            $table->string('tool')->index();
            $table->json('parameters')->nullable();
            $table->text('result_summary')->nullable();
            $table->string('status')->index();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcp_calls');
    }
};
