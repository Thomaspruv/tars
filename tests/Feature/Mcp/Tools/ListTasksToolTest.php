<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\ListTasksTool;
use App\Models\Entity;
use App\Models\Task;

test('lists open tasks', function () {
    Task::factory()->create(['title' => 'Relancer le plombier', 'status' => 'todo']);
    Task::factory()->done()->create(['title' => 'Tâche terminée']);

    TarsServer::tool(ListTasksTool::class)
        ->assertOk()
        ->assertSee('Relancer le plombier')
        ->assertDontSee('Tâche terminée');
});

test('filters by entity name', function () {
    $entity = Entity::factory()->create(['name' => 'SARL Alpha']);
    Task::factory()->create(['title' => 'Préparer la TVA', 'entity_id' => $entity->id, 'status' => 'todo']);
    Task::factory()->create(['title' => 'Autre tâche', 'status' => 'todo']);

    TarsServer::tool(ListTasksTool::class, ['entity' => 'sarl alpha'])
        ->assertOk()
        ->assertSee('Préparer la TVA')
        ->assertDontSee('Autre tâche');
});

test('returns candidates when the entity filter is ambiguous', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Entity::factory()->create(['name' => 'Appart Lilou']);

    TarsServer::tool(ListTasksTool::class, ['entity' => 'lil'])
        ->assertOk()
        ->assertSee('Précise laquelle');
});

test('reports when the entity filter matches nothing', function () {
    TarsServer::tool(ListTasksTool::class, ['entity' => 'introuvable'])
        ->assertHasErrors(['Aucune entité']);
});

test('says when there are no tasks', function () {
    TarsServer::tool(ListTasksTool::class)->assertOk()->assertSee('Aucune tâche.');
});
