<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            // Une veille : la conversation se réveille toute seule et demande à
            // l'agent de revérifier. `watch_every_minutes` nul = pas de veille,
            // ou veille terminée.
            $table->unsignedInteger('watch_every_minutes')->nullable()->after('turn');
            $table->timestamp('watch_until')->nullable()->after('watch_every_minutes');
            $table->timestamp('watch_next_at')->nullable()->after('watch_until');
            $table->unsignedInteger('watch_runs')->default(0)->after('watch_next_at');
            $table->string('watch_ended_reason')->nullable()->after('watch_runs');

            $table->index('watch_next_at');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn([
                'watch_every_minutes', 'watch_until', 'watch_next_at',
                'watch_runs', 'watch_ended_reason',
            ]);
        });
    }
};
