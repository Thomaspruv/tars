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
