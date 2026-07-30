<?php

use App\Enums\TaskStatus;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\CompleteTaskTool;
use App\Models\Task;

test('completes a task by approximate title', function () {
    $task = Task::factory()->create(['title' => 'Relancer le plombier', 'status' => 'todo']);

    TarsServer::tool(CompleteTaskTool::class, ['task' => 'relancer plombier'])
        ->assertOk()
        ->assertSee('marquée faite');

    expect($task->fresh()->status)->toBe(TaskStatus::Done);
});

test('advances a recurring task instead of completing it', function () {
    $task = Task::factory()->create([
        'title' => 'TVA mensuelle',
        'status' => 'todo',
        'due_date' => today(),
        'recurrence' => 'monthly',
    ]);

    TarsServer::tool(CompleteTaskTool::class, ['task' => 'tva mensuelle'])
        ->assertOk()
        ->assertSee('reportée');

    expect($task->fresh()->status)->toBe(TaskStatus::Todo);
});

test('returns candidates without completing anything when ambiguous', function () {
    Task::factory()->create(['title' => 'Appeler le plombier', 'status' => 'todo']);
    Task::factory()->create(['title' => 'Relancer le plombier', 'status' => 'todo']);

    TarsServer::tool(CompleteTaskTool::class, ['task' => 'plombier'])
        ->assertOk()
        ->assertSee('Précise laquelle');

    expect(Task::where('status', TaskStatus::Done)->count())->toBe(0);
});

test('reports when no open task matches', function () {
    TarsServer::tool(CompleteTaskTool::class, ['task' => 'introuvable'])
        ->assertHasErrors(['Aucune tâche']);
});

test('does not match a subtask, even by its exact title', function () {
    $parent = Task::factory()->create(['title' => 'Organiser la fête', 'status' => 'todo']);
    $subtask = Task::factory()->subtaskOf($parent)->create(['title' => 'Réserver la salle', 'status' => 'todo']);

    TarsServer::tool(CompleteTaskTool::class, ['task' => 'réserver la salle'])
        ->assertHasErrors(['Aucune tâche']);

    expect($subtask->fresh()->status)->toBe(TaskStatus::Todo);
});
