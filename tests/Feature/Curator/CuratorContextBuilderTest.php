<?php

use App\Enums\BrainSuggestionStatus;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Models\Checklist;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\McpCall;
use App\Models\Task;
use App\Support\Curator\CuratorContextBuilder;

test('todo mission selects only a-traiter notes under TARS/', function () {
    $todo = BrainDocument::factory()->create(['path' => 'TARS/2026-07-27-note.md', 'frontmatter' => ['type' => 'a-traiter']]);
    BrainDocument::factory()->create(['path' => 'TARS/2026-07-27-other.md', 'frontmatter' => ['type' => 'traite']]);
    BrainDocument::factory()->create(['path' => 'Notes/plombier.md', 'frontmatter' => ['type' => 'note']]);

    $notes = (new CuratorContextBuilder)->notesToProcess('todo');

    expect($notes)->toHaveCount(1)
        ->and($notes->first()->id)->toBe($todo->id);
});

test('tidy mission selects the whole sas plus unanchored notes, excluding Profil', function () {
    $sas = BrainDocument::factory()->create(['path' => 'TARS/2026-07-27-note.md']);
    $unanchored = BrainDocument::factory()->create(['path' => 'Notes/orpheline.md']);
    $anchoredEntity = Entity::factory()->create();
    $anchored = BrainDocument::factory()->create(['path' => 'Notes/rattachee.md']);
    $anchored->entities()->attach($anchoredEntity);
    BrainDocument::factory()->create(['path' => 'Profil/perso.md']);

    $notes = (new CuratorContextBuilder)->notesToProcess('tidy');

    $ids = $notes->pluck('id');
    expect($ids)->toContain($sas->id)
        ->and($ids)->toContain($unanchored->id)
        ->and($ids)->not->toContain($anchored->id);
});

test('tidy mission excludes unmanaged folders even when unanchored', function () {
    BrainDocument::factory()->create(['path' => 'ActiveContext/session.md']);

    $notes = (new CuratorContextBuilder)->notesToProcess('tidy');

    expect($notes)->toBeEmpty();
});

test('assembles the referential, living map, notes, mcp calls and open tasks sections', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Goal::factory()->create(['title' => 'Rénover la salle de bain']);
    BrainDocument::factory()->create(['path' => 'Notes/plombier.md', 'title' => 'Plombier']);
    BrainDocument::factory()->create(['path' => 'TARS/2026-07-27-note.md', 'frontmatter' => ['type' => 'a-traiter'], 'content' => 'Rappeler Marc jeudi.']);
    Task::factory()->create(['title' => 'Tâche ouverte existante', 'status' => 'todo']);
    Checklist::factory()->create(['name' => 'Courses']);
    McpCall::factory()->create(['tool' => 'add_task']);

    $context = (new CuratorContextBuilder)->build('todo');

    expect($context)
        ->toContain('## Référentiel d\'ancrage')
        ->toContain('Appart Lilas')
        ->toContain('Rénover la salle de bain')
        ->toContain('## Carte du vivant')
        ->toContain('Plombier')
        ->toContain('## Notes à traiter')
        ->toContain('Rappeler Marc jeudi.')
        ->toContain('## Appels MCP (48 dernières heures)')
        ->toContain('add_task')
        ->toContain('## Tâches ouvertes et listes existantes')
        ->toContain('Tâche ouverte existante')
        ->toContain('Courses');
});

test('includes recent rejections for the notes being processed, excludes rejections older than 30 days', function () {
    $note = BrainDocument::factory()->create(['path' => 'TARS/2026-07-27-note.md', 'frontmatter' => ['type' => 'a-traiter']]);

    BrainSuggestion::factory()->create([
        'brain_document_id' => $note->id,
        'status' => BrainSuggestionStatus::Rejected,
        'action' => 'anchor',
        'reason' => 'Pas pertinent',
        'created_at' => now()->subDays(10),
    ]);

    BrainSuggestion::factory()->create([
        'brain_document_id' => $note->id,
        'status' => BrainSuggestionStatus::Rejected,
        'action' => 'archive',
        'reason' => 'Trop vieux',
        'created_at' => now()->subDays(40),
    ]);

    $context = (new CuratorContextBuilder)->build('todo');

    expect($context)->toContain('## Refus récents (30 derniers jours)')
        ->toContain('anchor')
        ->not->toContain('archive');
});
