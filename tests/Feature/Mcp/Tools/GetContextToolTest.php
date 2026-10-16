<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetContextTool;
use App\Models\BrainDocument;
use App\Models\Checklist;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\JournalEntry;

test('summarizes current state and profile documents when the vault is configured', function () {
    config(['brain.remote_url' => 'git@example.com:vault.git']);
    Entity::factory()->create(['status' => 'active']);
    Goal::factory()->create(['status' => 'active']);
    BrainDocument::factory()->create(['path' => 'Profil/identite.md', 'title' => 'Identité', 'content' => 'Thomas, 30 ans.']);

    TarsServer::tool(GetContextTool::class)
        ->assertOk()
        ->assertSee('Identité')
        ->assertSee('Thomas, 30 ans.')
        ->assertSee('1 entité(s) active(s)');
});

test('returns only the state summary when the vault is not configured', function () {
    config(['brain.remote_url' => null]);
    Entity::factory()->create(['status' => 'active']);

    TarsServer::tool(GetContextTool::class)
        ->assertOk()
        ->assertSee('entité(s) active(s)');
});

test('includes existing list names in the summary', function () {
    Checklist::factory()->create(['name' => 'Courses']);
    Checklist::factory()->create(['name' => 'Travaux SDB']);

    TarsServer::tool(GetContextTool::class)
        ->assertOk()
        ->assertSee('Courses')
        ->assertSee('Travaux SDB');
});

test('mentions when there are no lists', function () {
    TarsServer::tool(GetContextTool::class)
        ->assertOk()
        ->assertSee('Aucune liste existante.');
});

test('never exposes journal content — confidentiality is by explicit get_journal only', function () {
    JournalEntry::factory()->create([
        'summary' => 'Contenu très personnel du journal',
        'mood_label' => 'secret',
    ]);

    TarsServer::tool(GetContextTool::class)
        ->assertOk()
        ->assertDontSee('Contenu très personnel du journal')
        ->assertDontSee('secret');
});

test('reports the memory: size, conformance rate and available summaries', function () {
    config(['brain.remote_url' => 'git@example.com:vault.git']);

    BrainDocument::factory()->create(['path' => 'Notes/conforme.md', 'is_conformant' => true]);
    BrainDocument::factory()->create(['path' => 'Notes/pas-conforme.md', 'is_conformant' => false]);
    BrainDocument::factory()->create(['path' => 'Personnes/_index.md', 'is_conformant' => true]);

    TarsServer::tool(GetContextTool::class, [])
        ->assertOk()
        ->assertSee('Mémoire : 3 note(s), 50 % conformes')
        ->assertSee('Sommaires disponibles : Personnes');
});

test('never includes the journal', function () {
    config(['brain.remote_url' => 'git@example.com:vault.git']);

    BrainDocument::factory()->create(['path' => 'Journal/2026/2026-10-16.md', 'content' => 'Journée difficile, humeur en berne.']);

    TarsServer::tool(GetContextTool::class, [])
        ->assertOk()
        ->assertDontSee('humeur en berne');
});
