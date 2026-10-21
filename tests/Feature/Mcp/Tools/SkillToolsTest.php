<?php

use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\FindSkillsTool;
use App\Mcp\Tools\InstallSkillTool;
use App\Mcp\Tools\ReadSkillTool;
use App\Models\Mission;
use App\Support\Missions\MissionCommandBuilder;
use App\Support\Missions\SkillCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/skill-tools-'.uniqid();
    config()->set('missions.skill_paths', ['global' => $this->root]);
    config()->set('missions.skill_lock_path', $this->root.'/absent.json');
    app(SkillCatalog::class)->forget();
});

afterEach(fn () => File::deleteDirectory($this->root));

function installSkillFile(string $slug, string $description, string $body = '# Mode d\'emploi'): void
{
    $root = test()->root;

    File::ensureDirectoryExists($root.'/'.$slug);
    File::put($root.'/'.$slug.'/SKILL.md', "---\nname: {$slug}\ndescription: \"{$description}\"\n---\n\n{$body}");

    app(SkillCatalog::class)->forget();
}

test('an agent finds what is already installed, and what exists elsewhere', function () {
    installSkillFile('pest-testing', 'Pour tester avec Pest.');

    Http::fake([
        'skills.sh/api/search*' => Http::response(['skills' => [
            ['source' => 'kepano/obsidian-skills', 'skillId' => 'obsidian-cli', 'name' => 'obsidian-cli', 'installs' => 78935],
        ]]),
        'skills.sh/*' => Http::response('<html><head><meta name="description" content="Piloter un vault Obsidian."/></head></html>'),
    ]);

    TarsServer::tool(FindSkillsTool::class, ['query' => 'obsidian'])
        ->assertOk()
        ->assertSee('kepano/obsidian-skills@obsidian-cli')
        ->assertSee('79 k installations')
        ->assertSee('Piloter un vault Obsidian.');
});

test('a skill installed under the searched name comes first, as usable right away', function () {
    installSkillFile('obsidian-cli', 'Piloter un vault Obsidian.');

    Http::fake(['skills.sh/*' => Http::response(['skills' => []])]);

    TarsServer::tool(FindSkillsTool::class, ['query' => 'obsidian'])
        ->assertOk()
        ->assertSee('Déjà installés')
        ->assertSee('obsidian-cli — Piloter un vault Obsidian.');
});

test('a search that finds nothing says so, instead of leaving the agent waiting', function () {

    Http::fake(['skills.sh/*' => Http::response(['skills' => []])]);
    TarsServer::tool(FindSkillsTool::class, ['query' => 'quelque chose qui nexiste pas'])
        ->assertOk()
        ->assertSee('Fais sans.');
});

/**
 * Le point entier de `read_skill` : l'agent applique le mode d'emploi dans le
 * tour même, sans installation ni redémarrage.
 */
test('an installed skill is handed over in full, right away', function () {
    installSkillFile('pest-testing', 'Pour tester.', "# Pest\n\nUtilise `php artisan make:test --pest`.");

    TarsServer::tool(ReadSkillTool::class, ['skill' => 'pest-testing'])
        ->assertOk()
        ->assertSee('php artisan make:test --pest');
});

test('a registry skill is read without being installed', function () {
    Process::fake(['*' => Process::result(output: <<<'TXT'
        You are being given a Skill to execute.

        <SKILL.md>
        ---
        name: obsidian-cli
        ---

        Lance `obsidian search` pour chercher dans le vault.
        </SKILL.md>
        TXT)]);

    TarsServer::tool(ReadSkillTool::class, ['skill' => 'kepano/obsidian-skills@obsidian-cli'])
        ->assertOk()
        ->assertSee('obsidian search');

    // Rien n'a été installé : le disque est inchangé.
    expect(app(SkillCatalog::class)->all())->toBe([]);
});

test('a name that cannot be read tells the agent how to write it', function () {
    TarsServer::tool(ReadSkillTool::class, ['skill' => 'fantome'])
        ->assertHasErrors();

    TarsServer::tool(ReadSkillTool::class, ['skill' => 'pas-un-depot@skill'])
        ->assertHasErrors();
});

test('installing is for later, and says so', function () {
    Process::fake(['*' => Process::result(output: 'Installed obsidian-cli')]);

    TarsServer::tool(InstallSkillTool::class, [
        'source' => 'kepano/obsidian-skills',
        'skill' => 'obsidian-cli',
    ])->assertOk()->assertSee('read_skill');

    Process::assertRan(fn ($process) => in_array('add', $process->command, true)
        && in_array('kepano/obsidian-skills', $process->command, true));
});

test('a repository name that is not one never reaches the shell', function () {
    Process::fake();

    TarsServer::tool(InstallSkillTool::class, ['source' => 'rm -rf /', 'skill' => 'x'])
        ->assertHasErrors();

    Process::assertNothingRan();
});

/**
 * Un outil qu'on ne nomme pas n'existe pas : l'agent ne parcourt pas sa liste
 * d'outils pour se demander s'il saurait mieux faire.
 */
test('every agent is told to look for a skill before improvising', function () {
    Storage::fake('local');
    config()->set('missions.run_disk', 'local');
    config()->set('missions.claude_binary', '/bin/claude');

    $command = app(MissionCommandBuilder::class)->build(Mission::factory()->create()->load('teamAgent'));

    $prompt = $command[array_search('--append-system-prompt', $command, true) + 1];

    expect($prompt)->toContain('find_skills')
        ->and($prompt)->toContain('read_skill');
});
