<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le texte sur lequel on cherche vraiment : titre et corps repliés en
 * minuscules sans accents, écrits à l'indexation.
 *
 * Sans lui, « réunion » et « reunion » sont deux mots différents en SQLite, et
 * le même mot en MySQL — une recherche qui ne rend pas la même chose en local
 * et en production n'est pas une recherche. La colonne porte la normalisation
 * une fois pour toutes, du côté de l'application, donc identique partout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brain_documents', function (Blueprint $table): void {
            $table->longText('search_text')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('brain_documents', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }
};
