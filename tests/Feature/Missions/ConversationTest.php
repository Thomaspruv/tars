<?php

use App\Enums\MissionStatus;
use App\Jobs\RunMissionJob;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\TeamAgent;
use App\Models\User;
use App\Support\Missions\MissionCommandBuilder;
use App\Support\Missions\MissionEventRecorder;
use App\Support\Missions\MissionLauncher;
use App\Support\Missions\SkillCatalog;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    Storage::fake('local');
    config()->set('missions.run_disk', 'local');
    config()->set('missions.claude_binary', '/bin/claude');
    config()->set('missions.skill_paths', []);
    app(SkillCatalog::class)->forget();
});

test('the first message opens the conversation and is part of the thread', function () {
    Queue::fake();

    $agent = TeamAgent::factory()->create(['slug' => 'documentaliste']);

    $mission = app(MissionLauncher::class)->open($agent, 'Range mes notes.');

    expect($mission->turn)->toBe(1)
        ->and($mission->currentPrompt())->toBe('Range mes notes.');

    $message = MissionEvent::where('mission_id', $mission->id)->sole();

    expect($message->subtype)->toBe(MissionEvent::PROMPT)
        ->and($message->isMine())->toBeTrue()
        ->and($message->turn)->toBe(1);
});

test('replying continues the same session instead of starting a new one', function () {
    Queue::fake();

    $mission = Mission::factory()->succeeded()->create();
    $mission->forceFill(['session_id' => '11111111-2222-3333-4444-555555555555'])->save();

    expect(app(MissionLauncher::class)->reply($mission, 'Et pour la semaine prochaine ?'))->toBeTrue();

    $mission->refresh();

    expect($mission->turn)->toBe(2)
        ->and($mission->status)->toBe(MissionStatus::Queued)
        ->and($mission->currentPrompt())->toBe('Et pour la semaine prochaine ?')
        // Le premier message continue de nommer la conversation dans les listes.
        ->and($mission->prompt)->not->toBe('Et pour la semaine prochaine ?');

    Queue::assertPushed(RunMissionJob::class);

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    // C'est tout ce qui sépare un échange d'une conversation : on reprend la
    // session au lieu d'en nommer une neuve.
    expect($command)->toContain('--resume')
        ->and($command)->not->toContain('--session-id')
        ->and(end($command))->toBe('Et pour la semaine prochaine ?');
});

test('an agent at work is not interrupted', function () {
    Queue::fake();

    $mission = Mission::factory()->running()->create();

    expect(app(MissionLauncher::class)->reply($mission, 'Attends, plutôt ça.'))->toBeFalse()
        ->and($mission->refresh()->turn)->toBe(1);

    Queue::assertNothingPushed();
});

test('a conversation can be picked up again after a failure', function () {
    Queue::fake();

    $mission = Mission::factory()->create(['status' => MissionStatus::Failed]);
    $mission->forceFill(['error' => 'Budget épuisé.', 'finished_at' => now()])->save();

    expect(app(MissionLauncher::class)->reply($mission, 'Réessaie.'))->toBeTrue();

    // L'échec précédent ne doit pas rester affiché au-dessus du nouveau tour.
    expect($mission->refresh()->error)->toBeNull()
        ->and($mission->status)->toBe(MissionStatus::Queued);
});

/**
 * Le CLI compte ce que *ce* tour a coûté, pas la conversation entière : sans
 * addition, un deuxième message effacerait le prix du premier.
 */
test('the cost of a conversation is the sum of its turns', function () {
    $mission = Mission::factory()->running()->create();

    $recorder = new MissionEventRecorder($mission);
    $recorder->record(['type' => 'result', 'subtype' => 'success', 'result' => 'Fait.', 'total_cost_usd' => 0.12, 'num_turns' => 2, 'usage' => ['output_tokens' => 50]]);

    expect((float) $mission->refresh()->cost_usd)->toBe(0.12);

    $mission->forceFill(['turn' => 2, 'status' => MissionStatus::Running])->save();

    (new MissionEventRecorder($mission))
        ->record(['type' => 'result', 'subtype' => 'success', 'result' => 'Fait aussi.', 'total_cost_usd' => 0.31, 'num_turns' => 1, 'usage' => ['output_tokens' => 20]]);

    $mission->refresh();

    expect((float) $mission->cost_usd)->toBe(0.43)
        ->and($mission->tokens_out)->toBe(70)
        ->and($mission->num_turns)->toBe(3)
        ->and($mission->result)->toBe('Fait aussi.');
});

test('a skill is activated once, not re-announced at every message', function () {
    $mission = Mission::factory()->create(['skill_slug' => 'pest-testing', 'prompt' => 'Le premier.']);

    $mission->forceFill(['turn' => 2])->save();
    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'turn' => 2,
        'subtype' => MissionEvent::PROMPT,
        'text' => 'Le deuxième.',
    ]);

    expect(app(MissionCommandBuilder::class)->prompt($mission->refresh()))->toBe('Le deuxième.');
});

test('the screen shows my own messages, and lets me write the next one', function () {
    Queue::fake();

    $mission = Mission::factory()->succeeded()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => MissionEvent::PROMPT,
        'text' => 'Range mes notes.',
    ]);
    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 2,
        'subtype' => 'text',
        'text' => 'Trois notes rangées.',
    ]);

    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSeeInOrder(['Range mes notes.', 'Trois notes rangées.'])
        ->set('message', 'Et les décisions ?')
        ->call('send')
        ->assertHasNoErrors();

    expect($mission->refresh()->turn)->toBe(2);

    Queue::assertPushed(RunMissionJob::class);
});

test('the writing area is closed while the agent works', function () {
    $mission = Mission::factory()->running()->create();

    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSee('travaille. Tu pourras lui répondre')
        ->assertDontSee('Envoyer');
});

/**
 * Un tour clos se lit comme une réponse ; celui qui tourne encore n'a pas dit
 * son dernier mot, et son texte reste une étape parmi les autres.
 */
test('a finished turn has an answer, the one in flight has none', function () {
    $mission = Mission::factory()->running()->create();
    $mission->forceFill(['turn' => 2])->save();

    MissionEvent::factory()->create(['mission_id' => $mission->id, 'seq' => 1, 'turn' => 1, 'subtype' => 'text', 'text' => 'Première réponse.']);
    MissionEvent::factory()->create(['mission_id' => $mission->id, 'seq' => 2, 'turn' => 2, 'subtype' => MissionEvent::PROMPT, 'text' => 'Et ensuite ?']);
    MissionEvent::factory()->create(['mission_id' => $mission->id, 'seq' => 3, 'turn' => 2, 'subtype' => 'text', 'text' => 'Je regarde.']);

    $turns = collect(Livewire::test('pages::missions', ['mission' => $mission])->get('turns'));

    $first = $turns->firstWhere('turn', 1);
    $second = $turns->firstWhere('turn', 2);

    expect($first['answer']['event']->text)->toBe('Première réponse.')
        ->and($first['work'])->toBe([])
        ->and($first['running'])->toBeFalse();

    expect($second['answer'])->toBeNull()
        ->and($second['running'])->toBeTrue()
        ->and($second['work'])->toHaveCount(1);
});

test('the messaging screen lists the conversations beside the open one', function () {
    $chief = TeamAgent::factory()->create(['name' => 'Le chef']);
    $hand = TeamAgent::factory()->create(['name' => 'Le documentaliste']);

    $open = Mission::factory()->for($chief, 'teamAgent')->succeeded()->create(['prompt' => 'Les films.']);
    Mission::factory()->for($hand, 'teamAgent')->running()->create(['prompt' => 'Range mes notes.']);

    // Les deux volets d'un coup : la liste à gauche, le fil ouvert à droite.
    Livewire::test('pages::missions', ['mission' => $open])
        ->assertSee('Nouvelle conversation')
        ->assertSee('Le documentaliste')
        ->assertSee('Le chef')
        ->assertSee('1 en cours');
});

/**
 * Thomas ne choisit pas son interlocuteur : il écrit au chef, et c'est au chef
 * de trouver la bonne personne dans son équipe.
 */
test('writing always goes to the chief, whoever else is on the team', function () {
    Queue::fake();

    TeamAgent::factory()->create(['slug' => 'documentaliste', 'name' => 'Le documentaliste']);
    TeamAgent::factory()->delegator()->create(['slug' => 'chef', 'name' => 'Le chef']);

    Livewire::test('pages::missions')
        ->assertSee('Le chef')
        ->assertDontSee('Le documentaliste · le cerveau')
        ->set('message', 'Occupe-toi de ma semaine.')
        ->call('send')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Mission::sole()->teamAgent->slug)->toBe('chef');
});

test('without a chief, the first agent available answers rather than nobody', function () {
    Queue::fake();

    $only = TeamAgent::factory()->create(['slug' => 'documentaliste']);

    Livewire::test('pages::missions')
        ->set('message', 'Occupe-toi de ma semaine.')
        ->call('send')
        ->assertHasNoErrors();

    expect(Mission::sole()->team_agent_id)->toBe($only->id);
});

test('with nobody at all, the screen says so instead of failing', function () {
    Queue::fake();

    Livewire::test('pages::missions')
        ->assertSee('Personne pour répondre')
        ->set('message', 'Occupe-toi de ma semaine.')
        ->call('send')
        ->assertHasErrors('message');

    Queue::assertNothingPushed();
});

test('an open conversation is what the screen shows, not the composer', function () {
    TeamAgent::factory()->delegator()->create(['name' => 'Le chef']);

    $mission = Mission::factory()->succeeded()->create();

    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSee('Lui répondre…')
        ->assertDontSee('Dis-lui ce que tu veux.');

    Livewire::test('pages::missions')
        ->assertSee('Dis-lui ce que tu veux.')
        ->assertDontSee('Lui répondre…');
});

/**
 * `?agent=` et `?skill=` sont des passages de relais, pas un état de la page :
 * accrochés à l'URL, ils suivaient la navigation et on se retrouvait sur
 * `/missions/7?agent=chef`, où le paramètre ne veut plus rien dire.
 */
test('a hand-off parameter does not follow me into the thread', function () {
    $mission = Mission::factory()->succeeded()->create();

    $html = Livewire::withQueryParams(['skill' => 'pest-testing'])
        ->test('pages::missions', ['mission' => $mission])
        ->html();

    // Le fil ouvert nomme son propre agent, et ne traîne pas le paramètre.
    expect($html)->not->toContain('?skill=')
        ->and($html)->toContain($mission->teamAgent->name);
});

test('a conversation opened by Hermes says so on screen', function () {
    $mine = Mission::factory()->succeeded()->create(['prompt' => 'La mienne.']);
    $his = Mission::factory()->fromHermes()->succeeded()->create(['prompt' => 'Celle d\'Hermès.']);

    // Une conversation que je n'ai pas ouverte ne doit pas se présenter comme
    // si je l'avais fait.
    Livewire::test('pages::missions', ['mission' => $his])
        ->assertSee('lancée par Hermès');

    Livewire::test('pages::missions', ['mission' => $mine])
        ->assertDontSee('lancée par Hermès')
        // Mais elle reste visible dans la liste, marquée.
        ->assertSee('Hermès');
});

/**
 * Un agent répond en markdown — titres, listes, gras. Affiché brut, on lit des
 * `**` au milieu des phrases.
 */
test('an answer written in markdown is read as markdown', function () {
    $mission = Mission::factory()->succeeded()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'text',
        'text' => "**Ce qui a été fait**\n\n- Un coéquipier a interrogé le site.\n- La connexion marche.",
    ]);

    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSee('<strong>Ce qui a été fait</strong>', false)
        ->assertSee('<li>Un coéquipier a interrogé le site.</li>', false)
        ->assertDontSee('**Ce qui a été fait**');
});
