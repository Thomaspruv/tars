<?php

use App\Enums\MissionStatus;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\TeamAgent;
use App\Models\User;
use Livewire\Livewire;

beforeEach(fn () => test()->actingAs(User::factory()->create()));

test('the board says who is working, and on what step', function () {
    $chief = TeamAgent::factory()->delegator()->create(['name' => 'Le chef']);
    $mission = Mission::factory()->for($chief, 'teamAgent')->running()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'tool_use',
        'tool_name' => 'Read',
        'text' => 'Projets/crm.md',
    ]);

    // L'étape en cours est la raison d'être de cet écran : savoir où il en est
    // sans ouvrir sa conversation.
    Livewire::test('pages::team')
        ->assertSee('Le chef')
        ->assertSee('1 au travail')
        ->assertSee('Read · Projets/crm.md')
        ->assertSee('délègue');
});

test('a delegation in flight is named as one', function () {
    $agent = TeamAgent::factory()->create();
    $mission = Mission::factory()->for($agent, 'teamAgent')->running()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'tool_use',
        'tool_name' => 'Agent',
        'text' => 'Ranger les notes',
        'payload' => ['input' => ['subagent_type' => 'documentaliste']],
    ]);

    Livewire::test('pages::team')->assertSee('missionne un coéquipier : Ranger les notes');
});

test('an idle agent shows what it did last, not nothing', function () {
    $agent = TeamAgent::factory()->create(['name' => 'Le documentaliste']);

    Mission::factory()->for($agent, 'teamAgent')->succeeded()->create(['prompt' => 'Range mes notes.']);

    Livewire::test('pages::team')
        ->assertSee('aucun au travail')
        ->assertSee("C'est fait.")
        ->assertDontSee("Il n'a encore rien fait.", false);
});

test('an agent that never worked says so plainly', function () {
    TeamAgent::factory()->create(['name' => 'Le nouveau']);

    Livewire::test('pages::team')
        ->assertSee('Le nouveau')
        ->assertSee("Il n'a encore rien fait.", false);
});

test('the board counts the conversations and what they cost this week', function () {
    $agent = TeamAgent::factory()->create();

    Mission::factory()->for($agent, 'teamAgent')->succeeded()->create(['cost_usd' => '0.2000']);
    Mission::factory()->for($agent, 'teamAgent')->succeeded()->create(['cost_usd' => '0.0500']);

    // Hors fenêtre : compté dans les conversations, pas dans la dépense de la
    // semaine.
    $old = Mission::factory()->for($agent, 'teamAgent')->succeeded()->create(['cost_usd' => '9.0000']);
    $old->forceFill(['created_at' => now()->subMonth()])->save();

    Livewire::test('pages::team')
        ->assertSee('3 conversations')
        ->assertSee('0.25 $ sur 7 jours');
});

/**
 * On ne choisit pas son interlocuteur depuis ce plateau : on écrit au chef, qui
 * répartit. Le plateau sert à *suivre*, et chaque carte mène à ce que son agent
 * a fait.
 */
test('each agent card leads to what it is doing, not to a way of picking it', function () {
    $agent = TeamAgent::factory()->create(['slug' => 'documentaliste']);
    $mission = Mission::factory()->for($agent, 'teamAgent')->succeeded()->create();

    Livewire::test('pages::team')
        ->assertSee('Relire')
        ->assertSee(route('missions.show', $mission), false)
        ->assertDontSee('Lui parler');
});

test('an agent at rest is marked as such', function () {
    TeamAgent::factory()->create(['name' => 'Le retraité', 'enabled' => false]);

    Livewire::test('pages::team')->assertSee('au repos');
});

test('the board is reachable as a tab of the agents space', function () {
    $this->get(route('team.index'))->assertSeeInOrder(['Missions', 'Équipe', 'Skills', 'Système']);
});

test('a queued mission shows as queued, not as working', function () {
    $agent = TeamAgent::factory()->create();
    Mission::factory()->for($agent, 'teamAgent')->create(['status' => MissionStatus::Queued]);

    Livewire::test('pages::team')->assertSee('en file');
});

/**
 * Le plateau existe pour ça : un sous-agent n'a pas de ligne dans `missions`,
 * il vit dans le process de celui qui l'a appelé. Sans cette lecture du flux,
 * trois agents au travail derrière un chef sont invisibles.
 */
test('a teammate at work under an agent is named, with its own step', function () {
    $chief = TeamAgent::factory()->delegator()->create(['name' => 'Le chef']);
    TeamAgent::factory()->create(['name' => 'Le chercheur', 'slug' => 'chercheur']);

    $mission = Mission::factory()->for($chief, 'teamAgent')->running()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'tool_use',
        'tool_use_id' => 'call_1',
        'tool_name' => 'Agent',
        'text' => 'Compare les offres',
        'payload' => ['input' => ['subagent_type' => 'chercheur']],
    ]);

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 2,
        'parent_tool_use_id' => 'call_1',
        'subtype' => 'tool_use',
        'tool_name' => 'WebFetch',
        'text' => 'legifrance.gouv.fr',
    ]);

    Livewire::test('pages::team')
        ->assertSee('1 au travail + 1 coéquipier')
        ->assertSee('Le chercheur')
        ->assertSee('WebFetch · legifrance.gouv.fr');
});

test('a teammate that has returned is no longer shown as working', function () {
    $chief = TeamAgent::factory()->delegator()->create();
    TeamAgent::factory()->create(['name' => 'Le chercheur', 'slug' => 'chercheur']);

    $mission = Mission::factory()->for($chief, 'teamAgent')->running()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'tool_use',
        'tool_use_id' => 'call_1',
        'tool_name' => 'Agent',
        'text' => 'Compare les offres',
        'payload' => ['input' => ['subagent_type' => 'chercheur']],
    ]);

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 2,
        'type' => 'user',
        'subtype' => 'tool_result',
        'tool_use_id' => 'call_1',
        'text' => 'Trois offres comparées.',
    ]);

    // Le chercheur garde sa ligne sur le banc — ce qui disparaît, c'est la
    // ligne indentée qui le disait au travail sous le chef.
    Livewire::test('pages::team')->assertDontSee('+ 1 coéquipier');
});

/**
 * Le travail d'un coéquipier ne doit pas passer pour celui du chef : le plateau
 * dirait que le chef lit un fichier qu'il n'a jamais ouvert.
 */
test("a teammate's step is not passed off as the chief's own", function () {
    $chief = TeamAgent::factory()->delegator()->create(['name' => 'Le chef']);
    $mission = Mission::factory()->for($chief, 'teamAgent')->running()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'subtype' => 'tool_use',
        'tool_name' => 'Grep',
        'text' => 'bail commercial',
    ]);

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 2,
        'parent_tool_use_id' => 'call_1',
        'subtype' => 'tool_use',
        'tool_name' => 'WebFetch',
        'text' => 'une page du coéquipier',
    ]);

    // L'étape de l'agent reste la sienne : la dernière ligne de *premier*
    // niveau, pas la dernière ligne du flux.
    Livewire::test('pages::team')->assertSee('Grep · bail commercial');
});

/**
 * Une veille qui dort entre deux passages n'est pas « au repos » : elle va se
 * réveiller et dépenser. L'écran doit dire qu'elle guette.
 */
test('an armed watch says it is watching, and when it comes back', function () {
    $agent = TeamAgent::factory()->create(['name' => 'Le veilleur']);

    Mission::factory()->for($agent, 'teamAgent')->succeeded()->watching(20)->create([
        'watch_runs' => 4,
        'watch_next_at' => now()->addMinutes(20),
    ]);

    Livewire::test('pages::team')
        ->assertSee('en veille')
        ->assertSee('toutes les 20 min')
        ->assertSee('4 passages')
        ->assertSee(now()->addMinutes(20)->translatedFormat('H\hi'));
});

/**
 * La dépense du jour se compte sur les passages, pas sur `missions.cost_usd` :
 * une veille ouverte la semaine dernière dépense aujourd'hui, et c'est
 * précisément celle qu'on veut voir.
 */
test('today spend counts the passes made today, whenever the mission was opened', function () {
    $agent = TeamAgent::factory()->create();

    $old = Mission::factory()->for($agent, 'teamAgent')->succeeded()->create(['cost_usd' => '9.0000']);
    $old->forceFill(['created_at' => now()->subWeek()])->save();

    MissionEvent::factory()->create([
        'mission_id' => $old->id,
        'seq' => 50,
        'type' => 'result',
        'payload' => ['total_cost_usd' => 0.35],
        'occurred_at' => now(),
    ]);

    MissionEvent::factory()->create([
        'mission_id' => $old->id,
        'seq' => 10,
        'type' => 'result',
        'payload' => ['total_cost_usd' => 8.65],
        'occurred_at' => now()->subWeek(),
    ]);

    Livewire::test('pages::team')->assertSee('0.35 $ aujourd\'hui');
});

test('a watch mid-pass says it was woken by a watch, not asked for', function () {
    $agent = TeamAgent::factory()->create();

    Mission::factory()->for($agent, 'teamAgent')->running()->watching()->create();

    Livewire::test('pages::team')
        ->assertSee('1 au travail')
        ->assertSee('veille');
});
