<?php

use App\Models\Mission;
use App\Models\TeamAgent;
use App\Support\Mcp\McpSettings;
use App\Support\Missions\MissionCommandBuilder;
use App\Support\Missions\SkillCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('missions.run_disk', 'local');
    config()->set('missions.claude_binary', '/bin/claude');

    $this->skillRoot = sys_get_temp_dir().'/tars-builder-skills-'.uniqid();
    config()->set('missions.skill_paths', ['projet' => $this->skillRoot]);
    config()->set('missions.skill_lock_path', $this->skillRoot.'/absent.json');
    app(SkillCatalog::class)->forget();
});

afterEach(fn () => File::deleteDirectory($this->skillRoot));

function installSkill(string $slug): string
{
    $root = test()->skillRoot;

    File::ensureDirectoryExists($root.'/'.$slug);
    File::put($root.'/'.$slug.'/SKILL.md', "---\nname: {$slug}\ndescription: \"Le skill {$slug}.\"\n---\n");

    app(SkillCatalog::class)->forget();

    return $root;
}

/**
 * La leçon la plus chère du projet : lancé sans précaution, le CLI hérite de
 * tous les serveurs MCP et plugins personnels de la machine — 104 000 tokens
 * de contexte parasite et 0,42 $ mesurés pour faire dire « bonjour ». Ces deux
 * drapeaux sont ce qui tient le coût d'une mission.
 */
test('a mission is launched in a closed configuration, not the machine own', function () {
    $mission = Mission::factory()->create();

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    expect($command)->toContain('--strict-mcp-config')
        ->and($command)->toContain('--setting-sources')
        ->and($command)->toContain('project')
        ->and($command[0])->toBe('/bin/claude');
});

test('the stream carries the delegation tree', function () {
    $mission = Mission::factory()->create();

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    // Sans ces trois-là, pas de visualisation : le flux est muet, ou arrive
    // d'un bloc à la fin, ou perd ce que font les sous-agents.
    expect($command)->toContain('--output-format')
        ->and($command)->toContain('stream-json')
        ->and($command)->toContain('--verbose')
        ->and($command)->toContain('--forward-subagent-text');
});

test('the budget ceiling and the session id are passed along', function () {
    $mission = Mission::factory()->create(['max_budget_usd' => '3.50']);
    $mission->forceFill(['session_id' => '11111111-2222-3333-4444-555555555555'])->save();

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    expect($command)->toContain('--max-budget-usd')
        ->and($command)->toContain('3.50')
        ->and($command)->toContain('--session-id')
        ->and($command)->toContain('11111111-2222-3333-4444-555555555555');
});

/**
 * Vérifié contre le vrai CLI : un skill n'est pas chargé par `--setting-sources
 * project` (cwd est le vault ou un dépôt, pas le dossier des skills), et
 * `--setting-sources user` le chargerait au prix de tous les greffons et
 * serveurs MCP personnels de la machine. Le greffon par mission est le seul
 * chemin qui donne un skill *et rien d'autre*.
 */
test('a chosen skill is carried by a per-mission plugin and activated by its slash command', function () {
    installSkill('pest-testing');

    $mission = Mission::factory()->create([
        'skill_slug' => 'pest-testing',
        'prompt' => 'Écris le test manquant.',
    ]);

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    expect($command)->toContain('--plugin-dir')
        ->and(Storage::disk('local')->exists($mission->runDirectory().'/plugin/.claude-plugin/plugin.json'))->toBeTrue();

    // Un skill chargé par un greffon vit sous le nom du greffon : `/pest-testing`
    // tout court n'activerait rien et arriverait comme du texte.
    expect(app(MissionCommandBuilder::class)->prompt($mission))
        ->toBe("/tars-mission:pest-testing\n\nÉcris le test manquant.");

    $manifest = json_decode(Storage::disk('local')->get($mission->runDirectory().'/plugin/.claude-plugin/plugin.json'), true);
    expect($manifest['name'])->toBe(MissionCommandBuilder::PLUGIN);
});

test('the skill folder is linked, not copied, so a mission runs the installed version', function () {
    $root = installSkill('livewire-development');

    $mission = Mission::factory()->create(['skill_slug' => 'livewire-development']);

    app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    $link = Storage::disk('local')->path($mission->runDirectory().'/plugin/skills/livewire-development');

    expect(is_link($link))->toBeTrue()
        ->and(realpath($link))->toBe(realpath($root.'/livewire-development'));
});

test('the agent own skills travel with it, even when the mission picks another', function () {
    installSkill('pest-testing');
    installSkill('laravel-best-practices');

    $agent = TeamAgent::factory()->create(['skill_slugs' => ['laravel-best-practices']]);
    $mission = Mission::factory()->for($agent, 'teamAgent')->create(['skill_slug' => 'pest-testing']);

    app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    $skills = Storage::disk('local')->path($mission->runDirectory().'/plugin/skills');

    expect(File::exists($skills.'/pest-testing'))->toBeTrue()
        ->and(File::exists($skills.'/laravel-best-practices'))->toBeTrue();
});

test('a skill that is not installed is not turned into a slash command', function () {
    $mission = Mission::factory()->create([
        'skill_slug' => 'fantome',
        'prompt' => 'Fais le travail.',
    ]);

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    expect(app(MissionCommandBuilder::class)->prompt($mission))->toBe('Fais le travail.')
        ->and($command)->not->toContain('--plugin-dir');
});

/**
 * Le prompt est un argument positionnel, et plusieurs options du CLI sont
 * variadiques : `--tools Read <prompt>` fait disparaître le prompt. Mesuré en
 * vrai, avec un `Error: Input must be provided...` à la clé.
 */
test('the prompt is the last argument, preceded by a single-valued flag', function () {
    installSkill('pest-testing');

    $command = app(MissionCommandBuilder::class)->build(
        Mission::factory()->create(['skill_slug' => 'pest-testing', 'prompt' => 'La consigne.'])->load('teamAgent')
    );

    $last = array_slice($command, -3);

    expect($last[0])->toBe('--plugin-dir')
        ->and($last[2])->toStartWith('/tars-mission:pest-testing');

    foreach (['--add-dir', '--mcp-config'] as $variadic) {
        $position = array_search($variadic, $command, true);

        if ($position !== false) {
            expect($command[$position + 2])->toStartWith('--');
        }
    }
});

test('a mission without a skill gets the bare prompt', function () {
    $mission = Mission::factory()->create(['skill_slug' => null, 'prompt' => 'Range mes notes.']);

    expect(app(MissionCommandBuilder::class)->prompt($mission))->toBe('Range mes notes.');
});

test('the tars mcp server is the only one declared, with its token', function () {
    $mcp = app(McpSettings::class);
    $mcp->setEnabled(true);
    $token = $mcp->generateToken();

    $mission = Mission::factory()->create();

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    expect($command)->toContain('--mcp-config');

    $config = json_decode(Storage::disk('local')->get($mission->runDirectory().'/mcp.json'), true);

    expect(array_keys($config['mcpServers']))->toBe(['tars'])
        ->and($config['mcpServers']['tars']['headers']['Authorization'])->toBe('Bearer '.$token);
});

test('no mcp config is written when the server is off', function () {
    app(McpSettings::class)->setEnabled(false);

    $mission = Mission::factory()->create();

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    expect($command)->not->toContain('--mcp-config')
        ->and(Storage::disk('local')->exists($mission->runDirectory().'/mcp.json'))->toBeFalse();
});

test('a chief is given its teammates, a lone agent is not', function () {
    $chief = TeamAgent::factory()->delegator()->create(['name' => 'Le chef']);
    $hand = TeamAgent::factory()->create(['name' => 'Le documentaliste', 'slug' => 'documentaliste']);

    $mission = Mission::factory()->for($chief, 'teamAgent')->create();

    $command = app(MissionCommandBuilder::class)->build($mission->load('teamAgent'));

    expect($command)->toContain('--agents');

    $definition = json_decode(Storage::disk('local')->get($mission->runDirectory().'/agents.json'), true);

    // Le chef ne se missionne pas lui-même, et un coéquipier ne reçoit pas de
    // sous-équipe : la hiérarchie est plate sous le chef, par construction.
    expect(array_keys($definition))->toBe([$hand->slug])
        ->and($definition[$hand->slug]['prompt'])->toBe($hand->role);

    $soloMission = Mission::factory()->for($hand, 'teamAgent')->create();

    expect(app(MissionCommandBuilder::class)->build($soloMission->load('teamAgent')))
        ->not->toContain('--agents');
});

test('the agent role becomes the system prompt, and a chief is told to delegate', function () {
    $chief = TeamAgent::factory()->delegator()->create(['role' => 'Tu es le chef.']);
    TeamAgent::factory()->create();

    $command = app(MissionCommandBuilder::class)->build(
        Mission::factory()->for($chief, 'teamAgent')->create()->load('teamAgent')
    );

    $prompt = $command[array_search('--append-system-prompt', $command, true) + 1];

    expect($prompt)->toStartWith('Tu es le chef.')
        ->and($prompt)->toContain('Task');
});
