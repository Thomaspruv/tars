<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\DeleteEntityTool;
use App\Models\Entity;
use App\Models\Task;

test('deletes an entity and detaches its open tasks', function () {
    $entity = Entity::factory()->create(['name' => 'Acme']);
    $task = Task::factory()->create(['entity_id' => $entity->id, 'status' => 'todo']);

    TarsServer::tool(DeleteEntityTool::class, ['entity' => 'acme'])
        ->assertOk()
        ->assertSee('supprimée');

    expect(Entity::find($entity->id))->toBeNull()
        ->and($task->fresh()->entity_id)->toBeNull();
});

test('fails without deleting anything when the entity is not found', function () {
    TarsServer::tool(DeleteEntityTool::class, ['entity' => 'introuvable'])->assertHasErrors(['Aucune entité']);
});

test('returns candidates without deleting anything when the entity is ambiguous', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Entity::factory()->create(['name' => 'Appart Lilou']);

    TarsServer::tool(DeleteEntityTool::class, ['entity' => 'lil'])->assertOk()->assertSee('Précise laquelle');

    expect(Entity::count())->toBe(2);
});
