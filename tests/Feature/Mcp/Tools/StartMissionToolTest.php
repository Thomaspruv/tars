<?php

use App\Enums\MissionStatus;
use App\Jobs\RunMissionJob;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\StartMissionTool;
use App\Models\Mission;
use App\Models\TeamAgent;
use App\Support\Missions\SkillCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('missions.skill_paths', []);
    app(SkillCatalog::class)->forget();
});

test('hermes confies a job to the chief, who picks who does it', function () {
    TeamAgent::factory()->create(['slug' => 'documentaliste', 'name' => 'Le documentaliste']);
    $chief = TeamAgent::factory()->delegator()->create(['slug' => 'chef', 'name' => 'Le chef']);

    TarsServer::tool(StartMissionTool::class, ['message' => 'Range mes notes de la semaine.'])
        ->assertOk()
        ->assertSee('Le chef');

    $mission = Mission::sole();

    // Hermès ne désigne personne : le chef reçoit, et c'est à lui de répartir.
    expect($mission->team_agent_id)->toBe($chief->id)
        ->and($mission->prompt)->toBe('Range mes notes de la semaine.')
        ->and($mission->status)->toBe(MissionStatus::Queued)
        ->and($mission->isFromHermes())->toBeTrue();

    Queue::assertPushed(RunMissionJob::class);
});

test('the answer tells where Thomas can watch it', function () {
    TeamAgent::factory()->delegator()->create();

    TarsServer::tool(StartMissionTool::class, ['message' => 'Fais le point sur mes objectifs.'])
        ->assertOk()
        ->assertSee(route('missions.show', Mission::sole()))
        ->assertSee('get_mission');
});

test('a skill that is not installed is refused rather than silently ignored', function () {
    TeamAgent::factory()->delegator()->create();

    TarsServer::tool(StartMissionTool::class, [
        'message' => 'Écris les tests manquants.',
        'skill' => 'fantome',
    ])->assertHasErrors();

    expect(Mission::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('an installed skill travels with the mission', function () {
    $root = sys_get_temp_dir().'/mcp-skills-'.uniqid();
    File::ensureDirectoryExists($root.'/pest-testing');
    File::put($root.'/pest-testing/SKILL.md', "---\nname: pest-testing\ndescription: \"Tester.\"\n---\n");
    config()->set('missions.skill_paths', ['global' => $root]);
    app(SkillCatalog::class)->forget();

    TeamAgent::factory()->delegator()->create();

    TarsServer::tool(StartMissionTool::class, [
        'message' => 'Écris les tests manquants.',
        'skill' => 'pest-testing',
    ])->assertOk();

    expect(Mission::sole()->skill_slug)->toBe('pest-testing');

    File::deleteDirectory($root);
});

test('the budget ceiling holds, whoever asks', function () {
    config()->set('missions.max_budget_usd', 5.0);
    TeamAgent::factory()->delegator()->create();

    TarsServer::tool(StartMissionTool::class, [
        'message' => 'Dépense tout.',
        'budget' => 500,
    ])->assertHasErrors();

    expect(Mission::count())->toBe(0);
});

test('without anyone on the team, hermes is told so plainly', function () {
    TarsServer::tool(StartMissionTool::class, ['message' => 'Range mes notes.'])
        ->assertHasErrors();

    Queue::assertNothingPushed();
});

test('hermes can ask for a watch, and is told what was set up', function () {
    TeamAgent::factory()->delegator()->create(['name' => 'Le chef']);

    TarsServer::tool(StartMissionTool::class, [
        'message' => 'Préviens-moi quand il y a des places pour le marathon de Lille.',
        'watch_every_minutes' => 30,
        'watch_for_hours' => 48,
    ])->assertOk()->assertSee('toutes les 30 minutes');

    $mission = Mission::sole();

    expect($mission->watch_every_minutes)->toBe(30)
        ->and($mission->isWatching())->toBeTrue()
        ->and($mission->watch_until?->isBetween(now()->addHours(47), now()->addHours(49)))->toBeTrue();
});

test('a watch asked for too often is slowed to the floor', function () {
    config()->set('missions.watch_min_minutes', 15);
    TeamAgent::factory()->delegator()->create();

    TarsServer::tool(StartMissionTool::class, [
        'message' => 'Surveille ça.',
        'watch_every_minutes' => 1,
    ])->assertOk();

    expect(Mission::sole()->watch_every_minutes)->toBe(15);
});
