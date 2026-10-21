<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('role');
            $table->string('model')->default('sonnet');
            $table->string('effort')->nullable();
            // Le terrain de l'agent : le vault d'Obsidian, ou un dépôt de code.
            // `workspace_path` nul sur un agent du vault signifie « le vault
            // configuré », pour qu'un déménagement du vault n'oblige pas à
            // retoucher l'équipe.
            $table->string('workspace_kind')->default('vault');
            $table->string('workspace_path')->nullable();
            $table->boolean('can_delegate')->default(false);
            $table->json('skill_slugs')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_agents');
    }
};
