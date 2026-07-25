<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetTodayTool;
use App\Models\Event;
use App\Models\Task;

test("summarizes today's tasks, upcoming events and next review", function () {
    Task::factory()->create(['title' => 'Tâche du jour', 'status' => 'todo', 'scheduled_date' => today()]);
    Event::factory()->create(['title' => 'RDV dentiste', 'starts_at' => today()->addDays(2)->setTime(10, 0)]);

    TarsServer::tool(GetTodayTool::class)
        ->assertOk()
        ->assertSee('Tâche du jour')
        ->assertSee('RDV dentiste')
        ->assertSee('revue');
});

test('says when nothing is scheduled', function () {
    TarsServer::tool(GetTodayTool::class)
        ->assertOk()
        ->assertSee('Aucune tâche.')
        ->assertSee('Aucun événement à venir.');
});
