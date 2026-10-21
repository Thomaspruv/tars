<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            // Qui a ouvert la conversation : Thomas depuis Tars, ou Hermès
            // depuis ses propres outils. Même vocabulaire que le journal et le
            // frontmatter du vault.
            $table->string('source')->default('thomas')->after('team_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
