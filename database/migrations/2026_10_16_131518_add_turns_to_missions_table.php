<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            // Une mission est une conversation : le tour courant, et le message
            // de ce tour. `prompt` reste le premier message, celui qui nomme la
            // mission dans les listes.
            $table->unsignedInteger('turn')->default(1)->after('prompt');
        });

        Schema::table('mission_events', function (Blueprint $table) {
            $table->unsignedInteger('turn')->default(1)->after('seq');
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropColumn('turn');
        });

        Schema::table('mission_events', function (Blueprint $table) {
            $table->dropColumn('turn');
        });
    }
};
