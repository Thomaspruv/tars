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
        Schema::table('entities', function (Blueprint $table) {
            $table->dropForeign(['life_area_id']);
            $table->dropColumn('life_area_id');
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->dropForeign(['life_area_id']);
            $table->dropColumn('life_area_id');
        });

        Schema::dropIfExists('life_areas');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('life_areas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color');
            $table->string('icon')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->foreignId('life_area_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('goals', function (Blueprint $table) {
            $table->foreignId('life_area_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }
};
