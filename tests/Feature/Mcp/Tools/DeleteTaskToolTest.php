<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\DeleteTaskTool;
use App\Models\Task;

test('deletes an open task', function () {
    $task = Task::factory()->create(['title' => 'Relancer le plombier', 'status' => 'todo']);

    TarsServer::tool(DeleteTaskTool::class, ['task' => 'plombier'])->assertOk()->assertSee('supprimée');

    expect(Task::find($task->id))->toBeNull();
});

test('deletes a done task too, unlike complete_task which only targets open tasks', function () {
    $task = Task::factory()->done()->create(['title' => 'Ancienne tâche']);

    TarsServer::tool(DeleteTaskTool::class, ['task' => 'ancienne'])->assertOk()->assertSee('supprimée');

    expect(Task::find($task->id))->toBeNull();
});

test('fails without deleting anything when the task is not found', function () {
    TarsServer::tool(DeleteTaskTool::class, ['task' => 'introuvable'])->assertHasErrors(['Aucune tâche']);
});
