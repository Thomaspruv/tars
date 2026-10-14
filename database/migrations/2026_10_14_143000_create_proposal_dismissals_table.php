<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // « Ignorer » n'est pas « non, jamais » : la proposition revient dans
        // une semaine si la situation n'a pas bougé.
        Schema::create('proposal_dismissals', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->timestamp('until');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_dismissals');
    }
};
