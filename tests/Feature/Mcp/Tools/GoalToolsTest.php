<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetGoalTool;
use App\Mcp\Tools\ListGoalsTool;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Task;

test('lists active goals with milestone progress', function () {
    $goal = Goal::factory()->create(['title' => 'Semi-marathon 2027', 'status' => 'active']);
    Milestone::factory()->create(['goal_id' => $goal->id, 'status' => 'done']);
    Milestone::factory()->create(['goal_id' => $goal->id, 'status' => 'pending']);
    Goal::factory()->create(['status' => 'paused']);

    TarsServer::tool(ListGoalsTool::class)
        ->assertOk()
        ->assertSee('Semi-marathon 2027 (1/2 jalons)');
});

test('gets goal detail by approximate title', function () {
    $goal = Goal::factory()->create(['title' => 'Rénover la salle de bain']);
    Task::factory()->create(['goal_id' => $goal->id, 'status' => 'todo']);
    Task::factory()->create(['goal_id' => $goal->id, 'status' => 'done']);

    TarsServer::tool(GetGoalTool::class, ['goal' => 'renover salle de bain'])
        ->assertOk()
        ->assertSee('1 tâche(s) ouverte(s)')
        ->assertSee('1 faite(s)');
});

test('returns candidates without side effect when the goal is ambiguous', function () {
    Goal::factory()->create(['title' => 'Rénover la salle de bain']);
    Goal::factory()->create(['title' => 'Rénover la cuisine']);

    TarsServer::tool(GetGoalTool::class, ['goal' => 'rénover'])
        ->assertOk()
        ->assertSee('Précise laquelle');
});

test('reports when no goal matches', function () {
    TarsServer::tool(GetGoalTool::class, ['goal' => 'introuvable'])
        ->assertHasErrors(['Aucun objectif']);
});
