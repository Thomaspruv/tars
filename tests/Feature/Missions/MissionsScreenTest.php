<?php

use App\Enums\MissionStatus;
use App\Jobs\RunMissionJob;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\Setting;
use App\Models\TeamAgent;
use App\Models\User;
use App\Support\Mcp\McpSettings;
use App\Support\Missions\SkillCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    config()->set('missions.skill_paths', []);
    app(SkillCatalog::class)->forget();
});

test('missioning an agent queues the mission and takes me to it', function () {
    Queue::fake();

    $agent = TeamAgent::factory()->delegator()->create(['slug' => 'chef', 'model' => 'sonnet']);

    Livewire::test('pages::missions')
        ->set('message', 'Range mes notes de la semaine.')
        ->set('budget', '1.50')
        ->call('send')
        ->assertHasNoErrors()
        ->assertRedirect();

    $mission = Mission::sole();

    expect($mission->team_agent_id)->toBe($agent->id)
        ->and($mission->status)->toBe(MissionStatus::Queued)
        ->and($mission->prompt)->toBe('Range mes notes de la semaine.')
        ->and((float) $mission->max_budget_usd)->toBe(1.5)
        ->and($mission->workspace_path)->toBe($agent->workspacePath())
        ->and($mission->session_id)->not->toBeNull();

    // Rien ne tourne dans la requête web : le CLI a besoin du trousseau de
    // Thomas, que le PHP-FPM de Valet n'a pas.
    Queue::assertPushed(RunMissionJob::class, fn (RunMissionJob $job) => $job->missionId === $mission->id);
});

test('a conversation needs something to say', function () {
    Queue::fake();

    TeamAgent::factory()->delegator()->create();

    Livewire::test('pages::missions')
        ->set('message', '')
        ->call('send')
        ->assertHasErrors(['message' => 'required']);

    Livewire::test('pages::missions')
        ->set('message', 'ok')
        ->call('send')
        ->assertHasErrors(['message' => 'min']);

    Queue::assertNothingPushed();
});

test('the budget cannot be raised above the ceiling', function () {
    Queue::fake();

    config()->set('missions.max_budget_usd', 5.0);
    TeamAgent::factory()->delegator()->create();

    Livewire::test('pages::missions')
        ->set('message', 'Dépense tout.')
        ->set('budget', '500')
        ->call('send')
        ->assertHasErrors(['budget']);

    Queue::assertNothingPushed();
});

test('an unarmed MCP server is said before launching, not after', function () {
    app(McpSettings::class)->setEnabled(false);
    config()->set('missions.claude_binary', '/bin/claude');

    Livewire::test('pages::missions')->assertSee('Le serveur MCP est éteint');

    app(McpSettings::class)->setEnabled(true);

    // Un jeton d'avant qu'on le conserve : le hash est là, le clair non.
    Setting::updateOrCreate(['key' => 'mcp.token_hash'], ['value' => hash('sha256', 'ancien')]);

    Livewire::test('pages::missions')->assertSee('Aucun jeton MCP exploitable');
});

test('a missing CLI binary is said too', function () {
    config()->set('missions.claude_binary', '/nowhere/claude');

    Livewire::test('pages::missions')->assertSee('Binaire Claude Code introuvable');

    config()->set('missions.claude_binary', '/bin/sh');

    Livewire::test('pages::missions')->assertDontSee('Binaire Claude Code introuvable');
})->skip(fn () => ! File::exists('/bin/sh'), 'Pas de /bin/sh sur cette machine.');

test('the live screen names who is speaking in each branch', function () {
    $chief = TeamAgent::factory()->delegator()->create(['name' => 'Le chef', 'slug' => 'chef']);
    TeamAgent::factory()->create(['name' => 'Le documentaliste', 'slug' => 'documentaliste']);

    $mission = Mission::factory()->for($chief, 'teamAgent')->running()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'type' => 'assistant',
        'subtype' => 'text',
        'text' => 'Je commence par répartir.',
    ]);
    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 2,
        'type' => 'assistant',
        'subtype' => 'tool_use',
        'tool_use_id' => 'toolu_task',
        'tool_name' => 'Agent',
        'text' => 'Ranger les notes',
        'payload' => ['input' => ['subagent_type' => 'documentaliste']],
    ]);
    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 3,
        'type' => 'assistant',
        'subtype' => 'text',
        'parent_tool_use_id' => 'toolu_task',
        'text' => 'Trois notes rangées.',
    ]);

    // Qui travaille est la première question devant cet écran : l'agent
    // principal est le titre, le coéquipier est le titre de sa branche.
    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSeeInOrder(['Le chef', 'Je commence par répartir.', 'Le documentaliste', 'Trois notes rangées.'])
        ->assertSee('1 délégation')
        ->assertSee('au travail');
});

test('a delegate that is no longer on the team keeps at least its slug', function () {
    $mission = Mission::factory()->running()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'type' => 'assistant',
        'subtype' => 'tool_use',
        'tool_use_id' => 'toolu_task',
        'tool_name' => 'Agent',
        'text' => 'Une tâche',
        'payload' => ['input' => ['subagent_type' => 'disparu']],
    ]);

    Livewire::test('pages::missions', ['mission' => $mission])->assertSee('disparu');
});

/**
 * La conclusion n'est pas une étape de plus : c'est ce que Thomas est venu
 * lire. Tant que la mission tourne, le dernier mot n'est pas encore le dernier.
 */
/**
 * Ce qu'il a fait est vrai mais secondaire : il se replie derrière un compteur
 * d'étapes dès que la réponse est là, et la réponse se lit en clair.
 */
test('the work folds away once the answer is there', function () {
    $mission = Mission::factory()->running()->create();

    MissionEvent::factory()->create(['mission_id' => $mission->id, 'seq' => 1, 'subtype' => 'text', 'text' => 'Une étape.']);
    MissionEvent::factory()->create(['mission_id' => $mission->id, 'seq' => 2, 'subtype' => 'text', 'text' => 'Mon rapport final.']);

    // Tant qu'il travaille, tout est une étape et le bloc reste ouvert.
    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSee('au travail')
        ->assertSee('{ open: true }', false);

    $mission->forceFill(['status' => MissionStatus::Succeeded, 'finished_at' => now()])->save();

    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSeeInOrder(['1 étape', 'Mon rapport final.'])
        ->assertSee('{ open: false }', false)
        ->assertDontSee('au travail');
});

/**
 * Mesuré sur une vraie mission : la 2.1 du CLI nomme l'outil `Agent`, pas
 * `Task` comme la documentation. L'arbre doit tenir dans les deux cas, sinon
 * une délégation s'affiche comme un appel d'outil anonyme.
 */
test('a delegation is recognised under either of its tool names', function () {
    TeamAgent::factory()->create(['name' => 'Le documentaliste', 'slug' => 'documentaliste']);

    foreach (['Agent', 'Task'] as $index => $toolName) {
        $mission = Mission::factory()->running()->create();

        MissionEvent::factory()->create([
            'mission_id' => $mission->id,
            'seq' => 1,
            'type' => 'assistant',
            'subtype' => 'tool_use',
            'tool_use_id' => 'toolu_'.$index,
            'tool_name' => $toolName,
            'text' => 'Lister les dossiers',
            'payload' => ['input' => ['subagent_type' => 'documentaliste']],
        ]);
        MissionEvent::factory()->create([
            'mission_id' => $mission->id,
            'seq' => 2,
            'type' => 'user',
            'subtype' => 'text',
            'parent_tool_use_id' => 'toolu_'.$index,
            'text' => 'Liste les dossiers du répertoire courant.',
        ]);
        MissionEvent::factory()->create([
            'mission_id' => $mission->id,
            'seq' => 3,
            'type' => 'assistant',
            'subtype' => 'text',
            'parent_tool_use_id' => 'toolu_'.$index,
            'text' => 'Dix dossiers.',
        ]);

        Livewire::test('pages::missions', ['mission' => $mission])
            ->assertSee('1 délégation')
            // La consigne donnée au coéquipier n'est pas la parole d'un agent :
            // elle est annoncée comme une consigne, avant ce qu'il répond.
            ->assertSeeInOrder(['Le documentaliste', 'Sa consigne', 'Liste les dossiers du répertoire courant.', 'Dix dossiers.'])
            ->assertDontSee('>'.$toolName.'</span>', false);
    }
});

test('the technical noise of the transport is not shown', function () {
    $mission = Mission::factory()->running()->create();

    foreach (['task_started', 'task_progress', 'task_updated', 'thinking_tokens'] as $index => $subtype) {
        MissionEvent::factory()->create([
            'mission_id' => $mission->id,
            'seq' => $index + 1,
            'type' => 'system',
            'subtype' => $subtype,
            'text' => 'bruit-'.$subtype,
        ]);
    }

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 9,
        'type' => 'rate_limit_event',
        'subtype' => null,
        'text' => 'bruit-rate-limit',
    ]);

    $screen = Livewire::test('pages::missions', ['mission' => $mission]);

    foreach (['task_started', 'task_progress', 'task_updated', 'thinking_tokens', 'rate-limit'] as $noise) {
        $screen->assertDontSee('bruit-'.$noise);
    }
});

test('a tool result is folded into the call it answers', function () {
    $mission = Mission::factory()->running()->create();

    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 1,
        'type' => 'assistant',
        'subtype' => 'tool_use',
        'tool_use_id' => 'toolu_read',
        'tool_name' => 'Read',
        'text' => 'Projets/crm.md',
    ]);
    MissionEvent::factory()->create([
        'mission_id' => $mission->id,
        'seq' => 2,
        'type' => 'user',
        'subtype' => 'tool_result',
        'tool_use_id' => 'toolu_read',
        'text' => 'le contenu de la note',
    ]);

    // Un appel et son retour sont une seule chose à lire : le retour ne fait
    // pas une ligne de plus dans le flux.
    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSee('Read')
        ->assertSee('Projets/crm.md')
        ->assertSee('le contenu de la note')
        ->assertDontSee('en cours…');
});

test('stopping a running mission raises the flag the worker watches', function () {
    $mission = Mission::factory()->running()->create();

    Livewire::test('pages::missions', ['mission' => $mission])->call('stop');

    $mission->refresh();

    // Le process vit dans le worker : la requête web lève un drapeau, elle ne
    // tue rien elle-même.
    expect($mission->cancel_requested)->toBeTrue()
        ->and($mission->status)->toBe(MissionStatus::Running);
});

test('stopping a mission still in the queue cancels it on the spot', function () {
    $mission = Mission::factory()->create();

    Livewire::test('pages::missions', ['mission' => $mission])->call('stop');

    expect($mission->refresh()->status)->toBe(MissionStatus::Cancelled);
});

test('the skills screen lists what is installed and sends it to a mission', function () {
    $root = sys_get_temp_dir().'/tars-skills-screen-'.uniqid();
    File::ensureDirectoryExists($root.'/projet/pest-testing');
    File::put($root.'/projet/pest-testing/SKILL.md', "---\nname: pest-testing\ndescription: \"Pour tester avec Pest.\"\n---\n");

    config()->set('missions.skill_paths', ['projet' => $root.'/projet']);
    app(SkillCatalog::class)->forget();

    Livewire::test('pages::skills')
        ->assertSee('pest-testing')
        ->assertSee('Pour tester avec Pest.')
        ->assertSee(route('missions.index', ['skill' => 'pest-testing']), false);

    File::deleteDirectory($root);
});

test('an install only accepts a repository name', function () {
    Livewire::test('pages::skills')
        ->set('package', 'rm -rf /')
        ->call('install')
        ->assertHasErrors('package');
});

test('the skills screen searches the skills.sh registry and installs from it', function () {
    Http::fake(['skills.sh/*' => Http::response(['skills' => [
        ['source' => 'kepano/obsidian-skills', 'skillId' => 'obsidian-cli', 'name' => 'obsidian-cli', 'installs' => 78935],
    ]])]);
    Process::fake(['*' => Process::result(output: 'Installed obsidian-cli')]);

    Livewire::test('pages::skills')
        // Rien n'est cherché tant qu'il n'y a pas deux signes : on ne tape pas
        // le registre à la première lettre.
        ->assertDontSee('Sur skills.sh')
        ->set('query', 'obsidian')
        ->assertSee('obsidian-cli')
        ->assertSee('kepano/obsidian-skills')
        ->assertSee('79 k')
        ->call('installFromRegistry', 'kepano/obsidian-skills', 'obsidian-cli')
        ->assertSee('Installed obsidian-cli');

    Process::assertRan(fn ($process) => in_array('--skill', $process->command, true)
        && in_array('obsidian-cli', $process->command, true));
});

test('what a skill does is asked only for the one being looked at', function () {
    Http::fake(['skills.sh/*' => Http::response(['skills' => [
        ['source' => 'a/b', 'skillId' => 'premier', 'name' => 'premier', 'installs' => 2],
        ['source' => 'a/b', 'skillId' => 'second', 'name' => 'second', 'installs' => 1],
    ]])]);
    Process::fake(['*' => Process::result(output: "---\nname: premier\ndescription: Ce qu'il fait.\n---\n")]);

    Livewire::test('pages::skills')
        ->set('query', 'quelque chose')
        ->assertDontSee("Ce qu'il fait.")
        ->call('describe', 'a/b', 'premier')
        ->assertSee("Ce qu'il fait.");

    // Un seul appel au CLI : la description du second n'a pas été demandée.
    Process::assertRanTimes(fn ($process) => in_array('use', $process->command, true), 1);
});

test('a stale registry entry says so on screen, instead of a wrong reason', function () {
    Http::fake(['skills.sh/*' => Http::response(['skills' => [
        ['source' => 'mattpocock/skills', 'skillId' => 'obsidian-vault', 'name' => 'obsidian-vault', 'installs' => 196000],
    ]])]);
    Process::fake(['*' => Process::result(output: 'No matching skill found for: obsidian-vault', exitCode: 1)]);

    Livewire::test('pages::skills')
        ->set('query', 'obsidian')
        ->call('describe', 'mattpocock/skills', 'obsidian-vault')
        ->assertSee("n'est plus dans son dépôt")
        ->assertDontSee('ne porte pas de description');
});

test('a registry that is down does not take the installed skills with it', function () {
    Http::fake(['skills.sh/*' => Http::response('nope', 503)]);

    $root = sys_get_temp_dir().'/tars-screen-registry-'.uniqid();
    File::ensureDirectoryExists($root.'/pest-testing');
    File::put($root.'/pest-testing/SKILL.md', "---\nname: pest-testing\ndescription: \"Toujours là.\"\n---\n");
    config()->set('missions.skill_paths', ['projet' => $root]);
    app(SkillCatalog::class)->forget();

    Livewire::test('pages::skills')
        ->set('query', 'obsidian')
        ->assertSee('Rien pour')
        ->assertSee('Toujours là.');

    File::deleteDirectory($root);
});
