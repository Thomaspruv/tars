<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brain_suggestions', function (Blueprint $table) {
            // Groups the suggestions of one conformance run, so Thomas can
            // accept or refuse a whole batch in one click.
            $table->string('batch_id')->nullable()->index()->after('agent_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('brain_suggestions', function (Blueprint $table) {
            $table->dropColumn('batch_id');
        });
    }
};
