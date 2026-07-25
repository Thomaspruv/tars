<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetContextTool;
use App\Models\BrainDocument;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\LifeArea;

test('summarizes current state and profile documents when the vault is configured', function () {
    config(['brain.remote_url' => 'git@example.com:vault.git']);
    LifeArea::factory()->create();
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
    LifeArea::factory()->create();

    TarsServer::tool(GetContextTool::class)
        ->assertOk()
        ->assertSee('domaine(s) de vie');
});
