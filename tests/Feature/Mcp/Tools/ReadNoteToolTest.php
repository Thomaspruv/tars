<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\ReadNoteTool;
use App\Models\BrainDocument;
use App\Models\McpCall;

beforeEach(function () {
    config(['brain.local_path' => '/tmp/read-note-vault', 'brain.remote_url' => 'git@example.com:vault.git']);
});

test('reads a note found by its approximate title', function () {
    BrainDocument::factory()->create([
        'path' => 'Personnes/marc-dupont.md',
        'title' => 'Marc Dupont',
        'frontmatter' => ['schema' => 3, 'type' => 'person', 'title' => 'Marc Dupont', 'date' => '2026-10-16'],
        'content' => 'Expert-comptable, cabinet Alpha.',
    ]);

    TarsServer::tool(ReadNoteTool::class, ['ref' => 'marc dupont'])
        ->assertOk()
        ->assertSee('Personnes/marc-dupont.md')
        ->assertSee('type: person')
        ->assertSee('Expert-comptable');

    expect(McpCall::where('tool', 'read_note')->exists())->toBeTrue();
});

test('reads a note found by its relative path', function () {
    BrainDocument::factory()->create(['path' => 'Notes/fiscalite-sarl.md', 'title' => 'Fiscalité SARL', 'content' => 'IS à 15 %.']);

    TarsServer::tool(ReadNoteTool::class, ['ref' => 'Notes/fiscalite-sarl.md'])
        ->assertOk()
        ->assertSee('IS à 15');
});

test('lists the candidates without reading anything when the reference is ambiguous', function () {
    BrainDocument::factory()->create(['path' => 'Personnes/marc-dupont.md', 'title' => 'Marc Dupont', 'content' => 'Le comptable.']);
    BrainDocument::factory()->create(['path' => 'Personnes/marc-dupond.md', 'title' => 'Marc Dupond', 'content' => 'Le voisin.']);

    TarsServer::tool(ReadNoteTool::class, ['ref' => 'Marc Dupon'])
        ->assertOk()
        ->assertSee('Précise laquelle')
        ->assertDontSee('Le comptable');

    expect(McpCall::where('tool', 'read_note')->first()->status->value)->toBe('ambiguous');
});

test('says so when nothing matches', function () {
    TarsServer::tool(ReadNoteTool::class, ['ref' => 'un truc qui n\'existe pas'])->assertHasErrors();
});

test('fails cleanly when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    TarsServer::tool(ReadNoteTool::class, ['ref' => 'Marc'])->assertHasErrors(['vault']);
});
