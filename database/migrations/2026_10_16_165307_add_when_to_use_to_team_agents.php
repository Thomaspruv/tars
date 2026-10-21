<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_agents', function (Blueprint $table) {
            // Deux textes qui ne s'adressent pas au même lecteur : `role` dit à
            // l'agent comment travailler, `when_to_use` dit au chef quand
            // l'appeler. Router sur un extrait du premier donnait des
            // aiguillages au hasard.
            $table->text('when_to_use')->nullable()->after('name');
            $table->unsignedInteger('position')->default(0)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('team_agents', function (Blueprint $table) {
            $table->dropColumn(['when_to_use', 'position']);
        });
    }
};
