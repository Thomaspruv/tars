<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\AddTaskTool;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Task;

test('creates a task with a title only', function () {
    TarsServer::tool(AddTaskTool::class, ['title' => 'Relancer le plombier'])
        ->assertOk()
        ->assertSee('Relancer le plombier');

    expect(Task::where('title', 'Relancer le plombier')->exists())->toBeTrue();
});

test('parses a french date and resolves entity/goal by name', function () {
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);
    $goal = Goal::factory()->create(['title' => 'Rénover la salle de bain']);

    TarsServer::tool(AddTaskTool::class, [
        'title' => 'Acheter du carrelage',
        'date' => 'demain',
        'entity' => 'lilas',
        'goal' => 'renover salle de bain',
        'priority' => 'p1',
    ])->assertOk();

    $task = Task::where('title', 'Acheter du carrelage')->firstOrFail();

    expect($task->entity_id)->toBe($entity->id)
        ->and($task->goal_id)->toBe($goal->id)
        ->and($task->scheduled_date->toDateString())->toBe(today()->addDay()->toDateString())
        ->and($task->priority->value)->toBe('p1');
});

test('returns candidates without creating anything when the entity is ambiguous', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Entity::factory()->create(['name' => 'Appart Lilou']);

    TarsServer::tool(AddTaskTool::class, ['title' => 'Test', 'entity' => 'lil'])
        ->assertOk()
        ->assertSee('Précise laquelle');

    expect(Task::where('title', 'Test')->exists())->toBeFalse();
});

test('fails without creating anything when the entity is not found', function () {
    TarsServer::tool(AddTaskTool::class, ['title' => 'Test', 'entity' => 'introuvable'])
        ->assertHasErrors(['Aucune entité']);

    expect(Task::where('title', 'Test')->exists())->toBeFalse();
});
