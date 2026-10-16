<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\ListNotesTool;
use App\Models\BrainDocument;
use App\Models\Entity;
use App\Models\McpCall;

beforeEach(function () {
    config(['brain.local_path' => '/tmp/list-notes-vault', 'brain.remote_url' => 'git@example.com:vault.git']);
});

test('lists the notes with their type, freshness and path', function () {
    BrainDocument::factory()->create([
        'path' => 'Personnes/marc-dupont.md',
        'title' => 'Marc Dupont',
        'note_type' => 'person',
        'updated_on' => '2026-10-16',
    ]);

    TarsServer::tool(ListNotesTool::class, [])
        ->assertOk()
        ->assertSee('Marc Dupont · Personne · 2026-10-16 · Personnes/marc-dupont.md');

    expect(McpCall::where('tool', 'list_notes')->exists())->toBeTrue();
});

test('filters by type and by status', function () {
    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc', 'note_type' => 'person']);
    BrainDocument::factory()->create(['path' => 'Veille/ia/2026-10-16-a.md', 'title' => 'Veille IA', 'note_type' => 'veille', 'status' => 'active']);
    BrainDocument::factory()->create(['path' => 'Veille/ia/2026-10-01-b.md', 'title' => 'Veille close', 'note_type' => 'veille', 'status' => 'done']);

    TarsServer::tool(ListNotesTool::class, ['type' => 'veille', 'status' => 'active'])
        ->assertOk()
        ->assertSee('Veille IA')
        ->assertDontSee('Veille close')
        ->assertDontSee('Marc');
});

test('filters by anchored entity', function () {
    $entity = Entity::factory()->create(['name' => 'SARL Alpha']);
    $anchored = BrainDocument::factory()->create(['path' => 'Réunions/2026-10-16-alpha.md', 'title' => 'Point Alpha', 'note_type' => 'meeting']);
    $anchored->entities()->attach($entity);
    BrainDocument::factory()->create(['path' => 'Notes/autre.md', 'title' => 'Autre chose', 'note_type' => 'note']);

    TarsServer::tool(ListNotesTool::class, ['entity' => 'sarl alpha'])
        ->assertOk()
        ->assertSee('Point Alpha')
        ->assertDontSee('Autre chose');
});

test('filters by a date written in French', function () {
    BrainDocument::factory()->create(['path' => 'Notes/recente.md', 'title' => 'Récente', 'note_type' => 'note', 'updated_on' => today()->toDateString(), 'mtime' => now()]);
    BrainDocument::factory()->create(['path' => 'Notes/ancienne.md', 'title' => 'Ancienne', 'note_type' => 'note', 'updated_on' => '2025-01-01', 'mtime' => '2025-01-01 10:00']);

    TarsServer::tool(ListNotesTool::class, ['since' => "aujourd'hui"])
        ->assertOk()
        ->assertSee('Récente')
        ->assertDontSee('Ancienne');
});

test('honours the limit, defaults to twenty, and lists the freshest first', function () {
    foreach (range(1, 25) as $index) {
        $slug = sprintf('note-%03d', $index);

        BrainDocument::factory()->create([
            'path' => "Notes/{$slug}.md",
            'title' => $slug,
            'note_type' => 'note',
            'updated_on' => now()->subDays(25 - $index)->toDateString(),
        ]);
    }

    // Twenty by default: the twentieth freshest is in, the twenty-first is out.
    TarsServer::tool(ListNotesTool::class, [])
        ->assertOk()
        ->assertSee('note-025')
        ->assertSee('note-006')
        ->assertDontSee('note-005');

    TarsServer::tool(ListNotesTool::class, ['limit' => 3])
        ->assertOk()
        ->assertSee('note-023')
        ->assertDontSee('note-022');
});

test('says so when nothing matches', function () {
    TarsServer::tool(ListNotesTool::class, ['type' => 'decision'])
        ->assertOk()
        ->assertSee('Aucune note ne correspond');
});

test('lists the candidates when the entity is ambiguous', function () {
    Entity::factory()->create(['name' => 'Marc Dupont']);
    Entity::factory()->create(['name' => 'Marc Dupond']);

    TarsServer::tool(ListNotesTool::class, ['entity' => 'Marc Dupon'])
        ->assertOk()
        ->assertSee('Précise laquelle');
});

test('fails cleanly when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    TarsServer::tool(ListNotesTool::class, [])->assertHasErrors(['vault']);
});
