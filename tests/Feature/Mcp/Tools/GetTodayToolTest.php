<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetTodayTool;
use App\Models\Event;
use App\Models\JournalEntry;
use App\Models\QuestionnaireRun;
use App\Models\Task;
use App\Support\Review\ReviewSettings;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

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

test('says the review is today when the configured weekly time has not passed yet', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-07 10:00:00')); // a Sunday, before the default 17:00

    TarsServer::tool(GetTodayTool::class)->assertOk()->assertSee("prévue aujourd'hui");
});

test('respects a custom weekly review time', function () {
    Carbon::setTestNow(Carbon::parse('2024-01-07 19:00:00')); // a Sunday at 19:00
    (new ReviewSettings)->updateWeeklyTime('20:00');

    TarsServer::tool(GetTodayTool::class)->assertOk()->assertSee("prévue aujourd'hui");
});

test('says the journal is not done yet when there is no entry today', function () {
    TarsServer::tool(GetTodayTool::class)->assertOk()->assertSee('Journal du jour : pas encore fait.');
});

test('says the journal is done when an entry exists today', function () {
    JournalEntry::factory()->create(['date' => today()]);

    TarsServer::tool(GetTodayTool::class)->assertOk()->assertSee('Journal du jour : fait.');
});

test('says when there is no bilan pending', function () {
    TarsServer::tool(GetTodayTool::class)->assertOk()->assertSee('Aucun bilan en attente.');
});

test('counts pending bilans', function () {
    QuestionnaireRun::factory()->create(['status' => 'pending']);
    QuestionnaireRun::factory()->create(['status' => 'pending']);
    QuestionnaireRun::factory()->completed()->create();

    TarsServer::tool(GetTodayTool::class)->assertOk()->assertSee('2 bilan(s) en attente.');
});
