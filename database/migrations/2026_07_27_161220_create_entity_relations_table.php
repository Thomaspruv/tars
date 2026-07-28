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
        // A prior deploy failed on the identifier-length bug fixed below —
        // Laravel compiles a composite unique() into a *separate* ALTER TABLE
        // after the CREATE TABLE, so that failure left the bare table behind
        // without recording this migration as run. Drop it first so this
        // migration is safe to re-run from that broken state.
        Schema::dropIfExists('entity_relations');

        Schema::create('entity_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('relation_type');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'related_entity_id', 'relation_type'], 'entity_relations_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_relations');
    }
};
