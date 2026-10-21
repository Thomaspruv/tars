<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            // La sentinelle : une vérification bête et gratuite qui garde la
            // porte de l'agent. Tant que l'empreinte de la page ne bouge pas,
            // on ne réveille personne — mesuré, un réveil coûte ~0,35 $.
            $table->string('watch_url')->nullable()->after('watch_max_usd');
            $table->string('watch_fingerprint', 64)->nullable()->after('watch_url');
            $table->timestamp('watch_checked_at')->nullable()->after('watch_fingerprint');
            $table->unsignedInteger('watch_skips')->default(0)->after('watch_checked_at');

            // Le tour auquel la session CLI courante a commencé. Une veille qui
            // reprend indéfiniment la même session traîne tous ses passages
            // précédents : le contexte, et donc le prix, enflent en silence.
            $table->unsignedInteger('session_started_turn')->default(1)->after('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn([
                'watch_url', 'watch_fingerprint', 'watch_checked_at',
                'watch_skips', 'session_started_turn',
            ]);
        });
    }
};
