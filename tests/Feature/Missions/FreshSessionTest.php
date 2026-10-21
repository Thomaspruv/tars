<?php

use App\Models\Mission;
use App\Models\MissionEvent;
use App\Support\Missions\MissionCommandBuilder;
use App\Support\Missions\MissionLauncher;
use App\Support\Missions\SkillCatalog;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');
    config()->set('missions.run_disk', 'local');
    config()->set('missions.claude_binary', '/bin/claude');
    config()->set('missions.skill_paths', []);
    app(SkillCatalog::class)->forget();
});

/**
 * Une veille qui reprend indéfiniment la même session traîne tous ses passages :
 * au cinquantième réveil, l'agent relit quarante-neuf échanges pour dire
 * « toujours rien », et le prix du passage grimpe avec le contexte — en
 * silence, puisque rien à l'écran ne le montre.
 */
test('a watch starts from a clean session every so often', function () {
    config()->set('missions.watch_fresh_session_every', 3);

    $mission = Mission::factory()->succeeded()->watching(15)->create(['prompt' => 'Surveille les places.']);
    $mission->forceFill(['watch_runs' => 3, 'turn' => 4])->save();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'text',
        'text' => 'Toujours complet.',
    ]);

    $sessionBefore = $mission->session_id;

    $this->artisan('missions:watch')->assertSuccessful();

    $mission->refresh();

    expect($mission->session_id)->not->toBe($sessionBefore)
        ->and($mission->resumesSession())->toBeFalse();

    // Il ne se souvient de rien : on lui rend le strict nécessaire.
    $prompt = $mission->currentPrompt();
    expect($prompt)->toContain('Surveille les places.')
        ->and($prompt)->toContain('Dernier état connu : Toujours complet.')
        ->and($prompt)->toContain('passage');

    // Et le CLI ouvre bien une session, au lieu d'en reprendre une.
    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));
    expect($command)->toContain('--session-id')
        ->and($command)->not->toContain('--resume');
});

test('between two restarts, the conversation is simply resumed', function () {
    config()->set('missions.watch_fresh_session_every', 3);

    $mission = Mission::factory()->succeeded()->watching(15)->create();
    $mission->forceFill(['watch_runs' => 1, 'turn' => 2])->save();

    $sessionBefore = $mission->session_id;

    $this->artisan('missions:watch')->assertSuccessful();

    $mission->refresh();

    expect($mission->session_id)->toBe($sessionBefore)
        ->and($mission->currentPrompt())->toContain('Revérifie maintenant')
        // La consigne d'origine est dans la conversation : inutile de la répéter.
        ->and($mission->currentPrompt())->not->toContain('Dernier état connu');

    expect(app(MissionCommandBuilder::class)->build($mission->load('teamAgent')))->toContain('--resume');
});

test('the thread survives a restarted session, it is only the CLI that forgets', function () {
    config()->set('missions.watch_fresh_session_every', 2);

    $mission = Mission::factory()->succeeded()->watching(15)->create();
    $mission->forceFill(['watch_runs' => 2, 'turn' => 3])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    $mission->refresh();

    // Le tour continue de monter : Thomas lit un seul fil, du début à la fin.
    expect($mission->turn)->toBe(4)
        ->and($mission->session_started_turn)->toBe(4);
});

test('a conversation of mine is never restarted behind my back', function () {
    config()->set('missions.watch_fresh_session_every', 1);

    $mission = Mission::factory()->succeeded()->create();
    $sessionBefore = $mission->session_id;

    app(MissionLauncher::class)->reply($mission, 'Et pour la semaine prochaine ?');

    expect($mission->refresh()->session_id)->toBe($sessionBefore)
        ->and($mission->resumesSession())->toBeTrue();
});
