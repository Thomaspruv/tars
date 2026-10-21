<?php

use App\Enums\MissionStatus;
use App\Enums\WatchEnded;
use App\Jobs\RunMissionJob;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\TeamAgent;
use App\Models\User;
use App\Support\Missions\MissionCommandBuilder;
use App\Support\Missions\MissionLauncher;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

/**
 * Une veille n'est pas une mission à part : c'est la même conversation qu'on
 * relance. C'est ce qui fait que l'agent se souvient de ses passages — il sait
 * qu'il a déjà regardé six fois, et ce qu'il a vu.
 */
test('a watch wakes the same conversation up, turn after turn', function () {
    $mission = Mission::factory()->succeeded()->watching(30)->create(['prompt' => 'Préviens-moi quand il y a des places.']);

    $this->artisan('missions:watch')->assertSuccessful();

    $mission->refresh();

    expect($mission->turn)->toBe(2)
        ->and($mission->status)->toBe(MissionStatus::Queued)
        ->and($mission->watch_runs)->toBe(1)
        ->and($mission->watch_next_at?->isFuture())->toBeTrue()
        ->and($mission->currentPrompt())->toContain('alert_thomas')
        // Le premier message continue de nommer la conversation.
        ->and($mission->prompt)->toBe('Préviens-moi quand il y a des places.');

    Queue::assertPushed(RunMissionJob::class);
});

test('a watch whose time has not come is left alone', function () {
    $mission = Mission::factory()->succeeded()->watching(30)->create();
    $mission->forceFill(['watch_next_at' => now()->addHour()])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->turn)->toBe(1);
    Queue::assertNothingPushed();
});

test('a watch never interrupts an agent at work', function () {
    $mission = Mission::factory()->running()->watching(30)->create();

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->watch_runs)->toBe(0);
    Queue::assertNothingPushed();
});

test('a watch stops when its deadline has passed', function () {
    $mission = Mission::factory()->succeeded()->watching(30)->create();
    $mission->forceFill(['watch_until' => now()->subHour()])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    $mission->refresh();

    expect($mission->isWatching())->toBeFalse()
        ->and($mission->watch_ended_reason)->toBe(WatchEnded::Expired);

    Queue::assertNothingPushed();
});

/**
 * Sans plafond, une veille dépenserait jusqu'à ce que Thomas s'en aperçoive.
 */
test('a watch stops after its last allowed pass', function () {
    config()->set('missions.watch_max_runs', 3);

    $mission = Mission::factory()->succeeded()->watching(30)->create();
    $mission->forceFill(['watch_runs' => 3])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->watch_ended_reason)->toBe(WatchEnded::Exhausted);
});

test('a watch that only ever fails is put out of its misery', function () {
    config()->set('missions.watch_max_failures', 2);

    $mission = Mission::factory()->watching(30)->create(['status' => MissionStatus::Failed]);
    $mission->forceFill(['watch_runs' => 2])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->watch_ended_reason)->toBe(WatchEnded::Failing);
});

test('a watch that failed once but has worked before keeps going', function () {
    config()->set('missions.watch_max_failures', 2);

    $mission = Mission::factory()->watching(30)->create(['status' => MissionStatus::Failed]);
    $mission->forceFill(['watch_runs' => 5])->save();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'type' => 'result',
        'subtype' => 'success',
    ]);

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->isWatching())->toBeTrue();
});

test('the interval has a floor, whoever asks for it', function () {
    config()->set('missions.watch_min_minutes', 15);

    $mission = app(MissionLauncher::class)->watch(Mission::factory()->create(), everyMinutes: 2);

    // Une veille qui se relance toutes les deux minutes dépense sans rien
    // apprendre de plus.
    expect($mission->watch_every_minutes)->toBe(15);
});

test('I can stop a watch without ending the conversation', function () {
    $mission = Mission::factory()->succeeded()->watching(30)->create();

    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSee('En veille')
        ->assertSee('Arrêter la veille')
        ->call('stopWatch');

    $mission->refresh();

    // Le fil reste : on peut encore lui parler, il ne se réveillera plus seul.
    expect($mission->isWatching())->toBeFalse()
        ->and($mission->watch_ended_reason)->toBe(WatchEnded::Stopped)
        ->and($mission->status)->toBe(MissionStatus::Succeeded);
});

test('a finished watch says why it stopped, rather than going quiet', function () {
    $mission = Mission::factory()->succeeded()->create();
    $mission->forceFill(['watch_ended_reason' => WatchEnded::Found, 'watch_runs' => 7])->save();

    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSee('Veille terminée après 7 passages')
        ->assertSee('il a trouvé')
        ->assertDontSee('En veille');
});

test('starting a watch from Tars sets it up on the conversation', function () {
    TeamAgent::factory()->delegator()->create();

    Livewire::test('pages::missions')
        ->set('message', 'Préviens-moi quand il y a des places pour le marathon de Lille.')
        ->set('watchEvery', '30')
        ->set('watchHours', '48')
        ->call('send')
        ->assertHasNoErrors();

    $mission = Mission::sole();

    expect($mission->watch_every_minutes)->toBe(30)
        ->and($mission->watch_until?->isBetween(now()->addHours(47), now()->addHours(49)))->toBeTrue();
});

test('a conversation without a watch stays a one-off', function () {
    TeamAgent::factory()->delegator()->create();

    Livewire::test('pages::missions')
        ->set('message', 'Range mes notes de la semaine.')
        ->call('send')
        ->assertHasNoErrors();

    expect(Mission::sole()->isWatching())->toBeFalse();
});

/**
 * Mesuré en vrai : un passage qui fouille le web coûte ~0,35 $. Sans plafond,
 * une veille de deux jours à quatre passages par heure dépenserait soixante-dix
 * dollars en silence. C'est la dépense, bien plus que le nombre de passages,
 * qui peut filer.
 */
test('a watch stops when it has spent what it was allowed', function () {
    $mission = Mission::factory()->succeeded()->watching(30)->create();
    $mission->forceFill(['watch_max_usd' => '5.00', 'cost_usd' => '5.10'])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    $mission->refresh();

    expect($mission->isWatching())->toBeFalse()
        ->and($mission->watch_ended_reason)->toBe(WatchEnded::TooExpensive);

    Queue::assertNothingPushed();
});

test('a watch below its ceiling keeps going, and shows what it has spent', function () {
    $mission = Mission::factory()->succeeded()->watching(30)->create();
    $mission->forceFill(['watch_max_usd' => '5.00', 'cost_usd' => '1.20'])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->isWatching())->toBeTrue();

    Livewire::test('pages::missions', ['mission' => $mission])->assertSee('1.20 / 5.00 $');
});

test('a watch is given a spending ceiling the moment it is set up', function () {
    config()->set('missions.watch_max_usd', 3.0);

    $mission = app(MissionLauncher::class)->watch(Mission::factory()->create(), everyMinutes: 30);

    expect((float) $mission->watch_max_usd)->toBe(3.0);
});

/**
 * Mesuré en vrai : au premier passage, l'agent a répondu « la surveillance
 * n'est pas en place, je n'ai rien programmé » — il ignorait que Tars allait le
 * réveiller. Le rappel n'arrivait qu'au deuxième passage.
 */
test('an agent on watch is told so from its very first pass', function () {
    $mission = Mission::factory()->watching(30)->create();

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    $prompt = $command[array_search('--append-system-prompt', $command, true) + 1];

    expect($prompt)->toContain('veille')
        ->and($prompt)->toContain('toutes les 30 minutes')
        ->and($prompt)->toContain("n'as donc rien à programmer")
        ->and($prompt)->toContain('alert_thomas');
});

test('a conversation that is not a watch says nothing about one', function () {
    $command = app(MissionCommandBuilder::class)
        ->build(Mission::factory()->create()->load('teamAgent'));

    $prompt = $command[array_search('--append-system-prompt', $command, true) + 1];

    expect($prompt)->not->toContain('veille');
});

/**
 * Une alerte est précisément ce pour quoi la veille existait : elle ne doit
 * jamais finir repliée dans le compteur d'étapes.
 */
test('an alert is never folded away with the rest of the work', function () {
    $mission = Mission::factory()->succeeded()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'tool_use',
        'tool_name' => 'WebFetch',
        'text' => 'marathon-de-lille.fr',
    ]);
    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 2,
        'type' => 'alert',
        'subtype' => 'alert',
        'text' => 'Des places se sont libérées.',
        'payload' => ['link' => 'https://marathon.example/inscription'],
    ]);
    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 3,
        'subtype' => 'text',
        'text' => 'Je t\'ai prévenu, il reste 40 dossards.',
    ]);

    $turn = collect(Livewire::test('pages::missions', ['mission' => $mission])->get('turns'))->first();

    expect($turn['alerts'])->toHaveCount(1)
        // Ni dans le bloc qu'on replie, ni confondue avec la réponse.
        ->and(collect($turn['work'])->pluck('event.type'))->not->toContain('alert')
        ->and($turn['answer']['event']->text)->toBe('Je t\'ai prévenu, il reste 40 dossards.');

    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSee("Il t'a prévenu", false)
        ->assertSee('Des places se sont libérées.')
        ->assertSee('https://marathon.example/inscription', false);
});

test('the state of a watch is shown above the thread, not buried under it', function () {
    $mission = Mission::factory()->succeeded()->watching(30)->create();

    $html = Livewire::test('pages::missions', ['mission' => $mission])->html();

    // Au-dessus de la conversation : c'est la première chose à savoir en
    // ouvrant une veille.
    expect(strpos($html, 'En veille'))->toBeLessThan((int) strpos($html, 'flex flex-col gap-7'));
});
