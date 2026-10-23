<?php

use App\Models\BrainDocument;
use App\Support\Brain\NoteSearch;
use Illuminate\Support\Facades\File;

/**
 * Ce que la recherche doit savoir faire pour qu'un agent la trouve utile.
 * L'ancienne version envoyait la requête entière dans un seul `LIKE` : une
 * question en langage naturel — la seule façon dont un agent cherche — ne
 * trouvait jamais rien.
 */
beforeEach(function () {
    $this->note = function (string $title, string $content): BrainDocument {
        return BrainDocument::factory()->create([
            'title' => $title,
            'content' => $content,
            'search_text' => NoteSearch::haystack($title, $content),
        ]);
    };
});

test('a natural-language question finds the note', function () {
    $wanted = ($this->note)('Spreak — Concurrence', 'Analyse du marché et des concurrents.');
    ($this->note)('Orchidées', 'Arrosage et rempotage.');

    $results = app(NoteSearch::class)->find('le marché concurrentiel de Spreak');

    expect($results->pluck('id'))->toContain($wanted->id)
        ->and($results->first()->id)->toBe($wanted->id);
});

test('every word of the query must be present', function () {
    ($this->note)('Spreak — Concurrence', 'Analyse du marché.');

    expect(app(NoteSearch::class)->find('Spreak concurrence'))->toHaveCount(1)
        ->and(app(NoteSearch::class)->find('Spreak fiscalité'))->toHaveCount(0);
});

test('accents and case do not matter', function () {
    $wanted = ($this->note)('Spreak — Écosystème Google', 'Dépendance à Google.');

    expect(app(NoteSearch::class)->find('ecosysteme google')->pluck('id'))->toContain($wanted->id)
        ->and(app(NoteSearch::class)->find('ÉCOSYSTÈME')->pluck('id'))->toContain($wanted->id);
});

test('a word in the title outranks the same word in the body', function () {
    $body = ($this->note)('Notes diverses', 'On a parlé de fiscalité SARL pendant la réunion.');
    $title = ($this->note)('Fiscalité SARL', 'Références et taux.');

    $results = app(NoteSearch::class)->find('fiscalité SARL');

    expect($results->first()->id)->toBe($title->id)
        ->and($results->pluck('id'))->toContain($body->id);
});

test('words too common to discriminate are dropped', function () {
    $wanted = ($this->note)('Fiche de Marc', 'Contact principal.');
    ($this->note)('Autre chose', 'Il y a de la place dans le garage.');

    // Sans retrait des mots courants, « de » et « la » suffiraient à faire
    // remonter la seconde note — et la conjonction ET ne filtrerait plus rien.
    expect(app(NoteSearch::class)->find('la fiche de Marc')->pluck('id'))
        ->toContain($wanted->id)
        ->toHaveCount(1);
});

test('a query made only of common words still searches rather than returning everything', function () {
    ($this->note)('Ce qui est en cours', 'Un contenu.');
    ($this->note)('Sans rapport', 'Autre chose.');

    expect(app(NoteSearch::class)->find('ce qui est'))->toHaveCount(1);
});

test('SQL wildcards in the query are not wildcards', function () {
    ($this->note)('Objectif 100 % conforme', 'Le contrat est tenu.');
    ($this->note)('Sans rapport', 'Autre chose.');

    // Sans échappement, « % » ramènerait tout le vault.
    expect(app(NoteSearch::class)->find('100 %'))->toHaveCount(1)
        ->and(app(NoteSearch::class)->find('%'))->toHaveCount(0);
});

test('an empty query finds nothing', function () {
    ($this->note)('Une note', 'Du contenu.');

    expect(app(NoteSearch::class)->find('  '))->toHaveCount(0);
});

test('the indexer fills the search text, and backfills a note that lacks it', function () {
    $vaultPath = sys_get_temp_dir().'/brain-search-'.uniqid();
    File::ensureDirectoryExists($vaultPath);
    config(['brain.local_path' => $vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    File::put($vaultPath.'/note.md', "# Réunion\n\nAvec Marc.");

    $this->artisan('brain:index');

    $document = BrainDocument::where('path', 'note.md')->firstOrFail();
    expect($document->search_text)->toContain('reunion');

    // Le hash n'a pas bougé, mais la colonne manque : la note est relue quand
    // même. C'est ce qui remplit un vault déjà indexé sans `--fresh`.
    $document->forceFill(['search_text' => null])->save();
    $this->artisan('brain:index');

    expect(BrainDocument::where('path', 'note.md')->value('search_text'))->toContain('reunion');

    File::deleteDirectory($vaultPath);
});

test('a query finds the word family, not just its exact form', function () {
    $wanted = ($this->note)('Spreak — Concurrence', 'Analyse du marché.');

    // « concurrentiel » et « concurrence » partagent leur radical : sans ça,
    // exiger tous les mots de la requête casse sur la première variante.
    expect(app(NoteSearch::class)->find('concurrentiel')->pluck('id'))->toContain($wanted->id)
        ->and(app(NoteSearch::class)->find('concurrents')->pluck('id'))->toContain($wanted->id);
});

test('a stem stays long enough to discriminate', function () {
    ($this->note)('Spécification produit', 'Le détail.');
    ($this->note)('Spécial', 'Autre chose.');

    expect(app(NoteSearch::class)->find('spécification'))->toHaveCount(1);
});
