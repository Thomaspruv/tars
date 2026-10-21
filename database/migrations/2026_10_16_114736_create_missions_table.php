<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_agent_id')->constrained()->cascadeOnDelete();
            $table->string('skill_slug')->nullable();
            $table->text('prompt');
            $table->string('status')->default('queued');
            $table->string('workspace_path');
            $table->string('model');
            $table->string('effort')->nullable();
            // L'identifiant de session est le nôtre (passé en `--session-id`) :
            // c'est lui qui permettra de reprendre une mission plus tard.
            $table->uuid('session_id')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->longText('result')->nullable();
            $table->text('error')->nullable();
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->decimal('max_budget_usd', 8, 2);
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->unsignedInteger('num_turns')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
