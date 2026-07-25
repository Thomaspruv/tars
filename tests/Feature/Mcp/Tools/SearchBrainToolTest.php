<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\SearchBrainTool;
use App\Models\BrainDocument;

test('finds documents by title or content', function () {
    BrainDocument::factory()->create(['title' => 'Réunion SARL Alpha', 'content' => 'Discussion budget.']);
    BrainDocument::factory()->create(['title' => 'Autre chose', 'content' => 'Sans rapport.']);

    TarsServer::tool(SearchBrainTool::class, ['query' => 'sarl alpha'])
        ->assertOk()
        ->assertSee('Réunion SARL Alpha')
        ->assertDontSee('Autre chose');
});

test('says when nothing matches', function () {
    TarsServer::tool(SearchBrainTool::class, ['query' => 'introuvable'])
        ->assertOk()
        ->assertSee('Aucune note ne correspond.');
});
