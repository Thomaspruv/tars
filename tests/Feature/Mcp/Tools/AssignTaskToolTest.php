<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\AssignTaskTool;
use App\Models\Entity;
use App\Models\Task;

test('assigns an open task to an entity', function () {
    $task = Task::factory()->create(['title' => 'Préparer la TVA', 'status' => 'todo', 'entity_id' => null]);
    $entity = Entity::factory()->create(['name' => 'Acme Corp']);

    TarsServer::tool(AssignTaskTool::class, ['task' => 'tva', 'entity' => 'acme'])
        ->assertOk()
        ->assertSee('assignée');

    expect($task->fresh()->entity_id)->toBe($entity->id);
});

test('reassigns a task already linked to another entity', function () {
    $oldEntity = Entity::factory()->create(['name' => 'Old Co']);
    $newEntity = Entity::factory()->create(['name' => 'New Co']);
    $task = Task::factory()->create(['title' => 'Test', 'status' => 'todo', 'entity_id' => $oldEntity->id]);

    TarsServer::tool(AssignTaskTool::class, ['task' => 'test', 'entity' => 'new co'])->assertOk();

    expect($task->fresh()->entity_id)->toBe($newEntity->id);
});

test('fails without changing anything when the task is not found', function () {
    Entity::factory()->create(['name' => 'Acme']);

    TarsServer::tool(AssignTaskTool::class, ['task' => 'introuvable', 'entity' => 'acme'])
        ->assertHasErrors(['Aucune tâche ouverte']);
});

test('fails without changing anything when the entity is not found', function () {
    $task = Task::factory()->create(['title' => 'Test', 'status' => 'todo']);

    TarsServer::tool(AssignTaskTool::class, ['task' => 'test', 'entity' => 'introuvable'])
        ->assertHasErrors(['Aucune entité']);

    expect($task->fresh()->entity_id)->toBeNull();
});
