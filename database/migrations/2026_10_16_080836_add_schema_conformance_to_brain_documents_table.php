<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brain_documents', function (Blueprint $table) {
            $table->unsignedTinyInteger('schema_version')->nullable()->after('frontmatter');
            $table->boolean('is_conformant')->default(false)->index()->after('schema_version');
            $table->string('note_type')->nullable()->index()->after('is_conformant');
            $table->string('status')->nullable()->after('note_type');
            // Ready for the Projets iteration (#8); written by nothing yet.
            $table->string('project_slug')->nullable()->index()->after('status');
            $table->date('updated_on')->nullable()->after('project_slug');
        });
    }

    public function down(): void
    {
        Schema::table('brain_documents', function (Blueprint $table) {
            $table->dropColumn(['schema_version', 'is_conformant', 'note_type', 'status', 'project_slug', 'updated_on']);
        });
    }
};
