<?php

use App\Support\Brain\VaultParser;

test('parses frontmatter and strips it from the content', function () {
    $raw = <<<'MD'
        ---
        title: Réunion SARL Alpha
        entity: SARL Alpha
        date: "2026-07-20"
        ---
        # Réunion SARL Alpha

        Compte-rendu ici.
        MD;

    $document = (new VaultParser)->parse($raw, 'fallback');

    expect($document->title)->toBe('Réunion SARL Alpha')
        ->and($document->frontmatter)->toMatchArray(['entity' => 'SARL Alpha', 'date' => '2026-07-20'])
        ->and($document->content)->not->toContain('---')
        ->and($document->content)->toContain('Compte-rendu ici.');
});

test('falls back to the first heading when there is no frontmatter title', function () {
    $document = (new VaultParser)->parse("# Titre du fichier\n\nCorps.", 'fallback');

    expect($document->title)->toBe('Titre du fichier');
});

test('falls back to the given filename when there is no frontmatter nor heading', function () {
    $document = (new VaultParser)->parse('Juste du texte.', 'nom-du-fichier');

    expect($document->title)->toBe('nom-du-fichier');
});

test('extracts unique wikilink targets, stripping aliases and headings', function () {
    $document = (new VaultParser)->parse(
        'Vu [[SARL Alpha]] et [[SARL Alpha]] encore, puis [[Appart Lilas|l\'appart]] et [[Autre note#Section]].',
        'fallback',
    );

    expect($document->wikilinks)->toBe(['SARL Alpha', 'Appart Lilas', 'Autre note']);
});

test('computes a stable sha256 hash of the raw content', function () {
    $document = (new VaultParser)->parse('contenu', 'fallback');

    expect($document->hash)->toBe(hash('sha256', 'contenu'));
});

test('treats content without a valid frontmatter block as plain content', function () {
    $document = (new VaultParser)->parse("---\nnot closed", 'fallback');

    expect($document->frontmatter)->toBe([])
        ->and($document->content)->toBe("---\nnot closed");
});
