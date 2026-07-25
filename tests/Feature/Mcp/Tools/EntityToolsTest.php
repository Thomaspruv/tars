<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetEntityTool;
use App\Mcp\Tools\ListEntitiesTool;
use App\Models\Checklist;
use App\Models\Entity;
use App\Models\Task;

test('lists active entities with their type', function () {
    Entity::factory()->create(['name' => 'SARL Alpha', 'type' => 'company', 'status' => 'active']);
    Entity::factory()->create(['name' => 'Archivée', 'status' => 'archived']);

    TarsServer::tool(ListEntitiesTool::class)
        ->assertOk()
        ->assertSee('SARL Alpha (company)')
        ->assertDontSee('Archivée');
});

test('gets entity detail by approximate name', function () {
    $entity = Entity::factory()->create(['name' => 'SARL Alpha']);
    Task::factory()->create(['entity_id' => $entity->id, 'status' => 'todo']);
    Checklist::factory()->create(['entity_id' => $entity->id, 'name' => 'Travaux']);

    TarsServer::tool(GetEntityTool::class, ['entity' => 'sarl alpha'])
        ->assertOk()
        ->assertSee('1 tâche(s) ouverte(s)')
        ->assertSee('Travaux');
});

test('returns candidates without side effect when the entity is ambiguous', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Entity::factory()->create(['name' => 'Appart Lilou']);

    TarsServer::tool(GetEntityTool::class, ['entity' => 'lil'])
        ->assertOk()
        ->assertSee('Précise laquelle');
});

test('reports when no entity matches', function () {
    TarsServer::tool(GetEntityTool::class, ['entity' => 'introuvable'])
        ->assertHasErrors(['Aucune entité']);
});
