<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->json('granted_scopes')->nullable()->after('google_account_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->dropColumn('granted_scopes');
        });
    }
};
