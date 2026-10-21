<?php

use App\Enums\MissionStatus;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\GetMissionTool;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\TeamAgent;

test('a finished mission gives back its answer and what it cost', function () {
    $mission = Mission::factory()
        ->for(TeamAgent::factory()->create(['name' => 'Le chef']), 'teamAgent')
        ->succeeded()
        ->create(['prompt' => 'Range mes notes.']);

    TarsServer::tool(GetMissionTool::class, ['id' => $mission->id])
        ->assertOk()
        ->assertSee('Le chef')
        ->assertSee('terminée')
        ->assertSee("C'est fait.")
        ->assertSee('0.04 $');
});

/**
 * Le piège de cet outil : Hermès raconte à Thomas. Une mission qui tourne
 * encore n'a pas de réponse, et il ne doit pas en inventer une.
 */
test('a mission still at work says what it is doing, and gives no answer', function () {
    $mission = Mission::factory()->running()->create(['prompt' => 'Fais le point.']);

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'tool_use',
        'tool_name' => 'Read',
        'text' => 'Projets/crm.md',
    ]);

    TarsServer::tool(GetMissionTool::class, ['id' => $mission->id])
        ->assertOk()
        ->assertSee('en cours')
        ->assertSee('En ce moment : Read — Projets/crm.md')
        ->assertDontSee('Réponse :');
});

test('a delegation in flight is told as one', function () {
    $mission = Mission::factory()->running()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'tool_use',
        'tool_name' => 'Agent',
        'text' => 'Ranger les notes',
        'payload' => ['input' => ['subagent_type' => 'documentaliste']],
    ]);

    TarsServer::tool(GetMissionTool::class, ['id' => $mission->id])
        ->assertOk()
        ->assertSee('il missionne un coéquipier — Ranger les notes');
});

test('a mission that has not started yet says that, not nothing', function () {
    $mission = Mission::factory()->create(['status' => MissionStatus::Queued]);

    TarsServer::tool(GetMissionTool::class, ['id' => $mission->id])
        ->assertOk()
        ->assertSee("Elle n'a pas encore commencé.");
});

test('a failure is reported as a failure', function () {
    $mission = Mission::factory()->create(['status' => MissionStatus::Failed]);
    $mission->forceFill(['error' => 'Budget épuisé.'])->save();

    TarsServer::tool(GetMissionTool::class, ['id' => $mission->id])
        ->assertOk()
        ->assertSee('échouée')
        ->assertSee('Erreur : Budget épuisé.');
});

test('without an id, the most recent one answers', function () {
    Mission::factory()->succeeded()->create(['prompt' => 'La vieille.']);
    Mission::factory()->succeeded()->create(['prompt' => 'La dernière.']);

    TarsServer::tool(GetMissionTool::class, [])
        ->assertOk()
        ->assertSee('La dernière.')
        ->assertDontSee('La vieille.');
});

test('an unknown mission is said to be unknown', function () {
    TarsServer::tool(GetMissionTool::class, ['id' => 404])->assertHasErrors();

    TarsServer::tool(GetMissionTool::class, [])->assertHasErrors();
});
