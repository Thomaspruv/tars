<?php

use App\Models\Mission;
use App\Models\TeamAgent;
use App\Models\User;
use App\Support\Missions\MissionCommandBuilder;
use App\Support\Missions\SkillCatalog;
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

/**
 * Le seul texte qui décide d'un aiguillage. Router sur un extrait du rôle —
 * écrit pour l'agent, pas pour celui qui route — donnait des choix au hasard.
 */
test('the chief routes on what each agent is for, not on who it is', function () {
    $chief = TeamAgent::factory()->delegator()->create();

    TeamAgent::factory()->create([
        'name' => 'Le chercheur',
        'slug' => 'chercheur',
        'when_to_use' => 'Quand la réponse est dehors : chercher sur le web, vérifier un fait.',
        'role' => 'Tu cherches dehors. Tu vérifies avant d\'affirmer.',
    ]);

    $mission = Mission::factory()->for($chief, 'teamAgent')->create();

    app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    $definition = json_decode(Storage::disk('local')->get($mission->runDirectory().'/agents.json'), true);

    expect($definition['chercheur']['description'])->toContain('Quand la réponse est dehors')
        // Le rôle reste le prompt de l'agent, il ne sert pas à router.
        ->and($definition['chercheur']['prompt'])->toContain("Tu vérifies avant d'affirmer");
});

test('an agent with no routing line falls back on its role rather than nothing', function () {
    $chief = TeamAgent::factory()->delegator()->create();
    TeamAgent::factory()->create(['slug' => 'muet', 'when_to_use' => null, 'role' => 'Tu fais des choses utiles.']);

    $mission = Mission::factory()->for($chief, 'teamAgent')->create();

    app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    $definition = json_decode(Storage::disk('local')->get($mission->runDirectory().'/agents.json'), true);

    expect($definition['muet']['description'])->toContain('Tu fais des choses utiles.');
});

test('I can recruit an agent, and it joins the bench', function () {
    TeamAgent::factory()->delegator()->create();

    Livewire::test('pages::team')
        ->call('recruit')
        ->set('name', 'Le comptable')
        ->set('whenToUse', 'Quand il faut regarder des chiffres, des factures ou des échéances fiscales.')
        ->set('role', 'Tu tiens les comptes de Thomas. Tu ne donnes jamais de conseil fiscal.')
        ->set('model', 'sonnet')
        ->call('save')
        ->assertHasNoErrors();

    $agent = TeamAgent::where('slug', 'le-comptable')->sole();

    expect($agent->name)->toBe('Le comptable')
        ->and($agent->when_to_use)->toContain('des factures')
        ->and($agent->enabled)->toBeTrue();
});

test('an agent without a reason to be called is refused', function () {
    TeamAgent::factory()->delegator()->create();

    Livewire::test('pages::team')
        ->call('recruit')
        ->set('name', 'Le mystérieux')
        ->set('whenToUse', '')
        ->set('role', 'Tu fais des choses, parfois.')
        ->call('save')
        ->assertHasErrors('whenToUse');

    expect(TeamAgent::where('slug', 'le-mysterieux')->exists())->toBeFalse();
});

test('two agents with the same name do not collide', function () {
    TeamAgent::factory()->create(['name' => 'Le chercheur', 'slug' => 'le-chercheur']);
    TeamAgent::factory()->delegator()->create();

    Livewire::test('pages::team')
        ->call('recruit')
        ->set('name', 'Le chercheur')
        ->set('whenToUse', 'Quand il faut chercher quelque chose de précis.')
        ->set('role', 'Tu cherches.')
        ->call('save')
        ->assertHasNoErrors();

    expect(TeamAgent::where('slug', 'le-chercheur-2')->exists())->toBeTrue();
});

test('editing an agent changes what the chief reads about it', function () {
    $agent = TeamAgent::factory()->create(['name' => 'Le chercheur', 'when_to_use' => 'Ancienne consigne.']);

    Livewire::test('pages::team')
        ->call('edit', $agent->id)
        ->assertSet('whenToUse', 'Ancienne consigne.')
        ->set('whenToUse', 'Quand la réponse est dehors et qu\'il faut la vérifier.')
        ->call('save')
        ->assertHasNoErrors();

    expect($agent->refresh()->when_to_use)->toContain('la réponse est dehors');
});

test('an agent put to rest is no longer offered to the chief', function () {
    $chief = TeamAgent::factory()->delegator()->create();
    $agent = TeamAgent::factory()->create(['slug' => 'chercheur']);

    Livewire::test('pages::team')->call('toggle', $agent->id);

    expect($agent->refresh()->enabled)->toBeFalse();

    $mission = Mission::factory()->for($chief, 'teamAgent')->create();
    app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    // Plus personne à missionner : aucune définition n'est écrite.
    expect(Storage::disk('local')->exists($mission->runDirectory().'/agents.json'))->toBeFalse();
});

/**
 * Sans chef, Thomas n'a plus d'interlocuteur du tout.
 */
test('the chief cannot be put to rest', function () {
    $chief = TeamAgent::factory()->delegator()->create();

    Livewire::test('pages::team')
        ->call('toggle', $chief->id)
        ->assertHasErrors('team');

    expect($chief->refresh()->enabled)->toBeTrue();
});

test('the board shows what each agent is for', function () {
    TeamAgent::factory()->create([
        'name' => 'Le chercheur',
        'when_to_use' => 'Quand la réponse est dehors : chercher sur le web.',
    ]);

    Livewire::test('pages::team')
        ->assertSee('Quand la réponse est dehors')
        ->assertSee('Recruter')
        ->assertSee('Modifier');
});
