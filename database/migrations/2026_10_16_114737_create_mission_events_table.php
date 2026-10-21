<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_id')->constrained()->cascadeOnDelete();
            // L'ordre d'arrivée, pas l'horodatage : deux événements d'une même
            // milliseconde doivent rester dans l'ordre où l'agent les a émis.
            $table->unsignedInteger('seq');
            $table->string('type');
            $table->string('subtype')->nullable();
            $table->string('tool_use_id')->nullable();
            // La clé de l'arbre : un événement produit par un sous-agent porte
            // l'identifiant de l'appel `Task` qui l'a engendré. C'est ce qui
            // rend la délégation visible, sans rien inventer de notre côté.
            $table->string('parent_tool_use_id')->nullable();
            $table->string('tool_name')->nullable();
            $table->longText('text')->nullable();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['mission_id', 'seq'], 'mission_events_seq_index');
            $table->index('parent_tool_use_id', 'mission_events_parent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_events');
    }
};
