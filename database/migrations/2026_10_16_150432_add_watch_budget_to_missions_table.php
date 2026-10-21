<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            // Le plafond de la veille entière, à ne pas confondre avec
            // `max_budget_usd` qui borne un seul passage. Mesuré : un passage
            // qui fouille le web coûte ~0,35 $ ; sans ce plafond, une veille de
            // deux jours dépenserait soixante-dix dollars en silence.
            $table->decimal('watch_max_usd', 8, 2)->nullable()->after('watch_runs');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn('watch_max_usd');
        });
    }
};
