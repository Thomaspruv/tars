<?php

use App\Support\Missions\RegistrySkill;
use App\Support\Missions\SkillCatalog;
use App\Support\Missions\SkillRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Le registre renvoie la liste ; chaque page de skill porte sa description
 * courte dans sa balise `<meta>`.
 *
 * @param  list<array<string, mixed>>  $skills
 * @param  array<string, string>  $pages  chemin → description
 */
function fakeRegistry(array $skills, array $pages = []): void
{
    $routes = ['skills.sh/api/search*' => Http::response(['query' => 'x', 'skills' => $skills])];

    foreach ($pages as $path => $description) {
        $routes['skills.sh/'.$path] = Http::response(
            '<html><head><meta name="description" content="'.$description.'"/></head></html>'
        );
    }

    // Une page dont on n'a pas défini le contenu n'a pas de description.
    $routes['skills.sh/*'] = Http::response('<html><head></head></html>');

    Http::fake($routes);
}

beforeEach(function () {
    config()->set('missions.skill_paths', []);
    app(SkillCatalog::class)->forget();
});

test('a search returns what the registry knows: a name, a repository, a popularity', function () {
    fakeRegistry([
        ['id' => 'kepano/obsidian-skills/obsidian-cli', 'source' => 'kepano/obsidian-skills', 'skillId' => 'obsidian-cli', 'name' => 'obsidian-cli', 'installs' => 78935],
    ]);

    $results = app(SkillRegistry::class)->search('obsidian');

    expect($results)->toHaveCount(1)
        ->and($results[0]->skillId)->toBe('obsidian-cli')
        ->and($results[0]->source)->toBe('kepano/obsidian-skills')
        ->and($results[0]->installsLabel())->toBe('79 k')
        ->and($results[0]->installed)->toBeFalse();
});

/**
 * L'API de recherche ne donne pas de description, et la demander au CLI coûte
 * une seconde par skill — inutilisable pour une liste. La page publique d'un
 * skill porte la sienne dans sa balise `<meta>` : on va chercher les pages
 * ensemble, une seule fois, et on les garde.
 */
test('every result carries a short description, fetched in one batch', function () {
    fakeRegistry(
        [
            ['source' => 'kepano/obsidian-skills', 'skillId' => 'obsidian-cli', 'name' => 'obsidian-cli', 'installs' => 78935],
            ['source' => 'a/b', 'skillId' => 'autre', 'name' => 'autre', 'installs' => 10],
        ],
        [
            'kepano/obsidian-skills/obsidian-cli' => 'Interact with Obsidian vaults using the Obsidian CLI.',
            'a/b/autre' => 'Fait autre chose.',
        ],
    );

    $results = app(SkillRegistry::class)->search('obsidian');

    expect($results[0]->description)->toBe('Interact with Obsidian vaults using the Obsidian CLI.')
        ->and($results[1]->description)->toBe('Fait autre chose.');

    // La recherche et les deux pages, pas une de plus.
    Http::assertSentCount(3);
});

test('the same skill description is not fetched twice', function () {
    fakeRegistry(
        [['source' => 'a/b', 'skillId' => 'c', 'name' => 'c', 'installs' => 1]],
        ['a/b/c' => 'Une description.'],
    );

    app(SkillRegistry::class)->search('premier');
    Cache::forget('missions.registry.'.md5('second'));
    app(SkillRegistry::class)->search('second');

    // Deux recherches, mais une seule page de skill : la description tient une
    // semaine, la recherche dix minutes.
    Http::assertSentCount(3);
});

test('html entities in a description come back as text', function () {
    fakeRegistry(
        [['source' => 'a/b', 'skillId' => 'c', 'name' => 'c', 'installs' => 1]],
        ['a/b/c' => 'Lire &amp; écrire des notes &quot;courtes&quot;'],
    );

    expect(app(SkillRegistry::class)->search('quoi')[0]->description)
        ->toBe('Lire & écrire des notes "courtes"');
});

test('a skill page without a description leaves the card standing', function () {
    fakeRegistry([['source' => 'a/b', 'skillId' => 'muet', 'name' => 'muet', 'installs' => 1]]);

    $results = app(SkillRegistry::class)->search('muet');

    expect($results)->toHaveCount(1)
        ->and($results[0]->description)->toBeNull();
});

test('a skill already installed is marked as such, not offered again', function () {
    $root = sys_get_temp_dir().'/tars-registry-'.uniqid();
    File::ensureDirectoryExists($root.'/pest-testing');
    File::put($root.'/pest-testing/SKILL.md', "---\nname: pest-testing\ndescription: \"Déjà là.\"\n---\n");

    config()->set('missions.skill_paths', ['global' => $root]);
    app(SkillCatalog::class)->forget();

    fakeRegistry([
        ['id' => 'laravel/skills/pest-testing', 'source' => 'laravel/skills', 'skillId' => 'pest-testing', 'name' => 'pest-testing', 'installs' => 10],
    ]);

    expect(app(SkillRegistry::class)->search('pest')[0]->installed)->toBeTrue();

    File::deleteDirectory($root);
});

/**
 * Le registre refuse en dessous de deux signes : on ne l'appelle pas pour se
 * faire dire non, et on ne cherche pas pendant que Thomas tape la première
 * lettre.
 */
test('a query shorter than two characters never reaches the registry', function () {
    Http::fake();

    expect(app(SkillRegistry::class)->search('o'))->toBe([])
        ->and(app(SkillRegistry::class)->search(' '))->toBe([]);

    Http::assertNothingSent();
});

test('a registry that is down leaves the screen standing', function () {
    Http::fake(['skills.sh/*' => Http::response('nope', 503)]);

    expect(app(SkillRegistry::class)->search('obsidian'))->toBe([]);
});

test('a malformed entry is dropped rather than breaking the list', function () {
    fakeRegistry([
        ['source' => 'a/b', 'skillId' => 'bon', 'name' => 'bon', 'installs' => 5],
        ['source' => 'a/b'],
        'pas un objet',
    ]);

    expect(app(SkillRegistry::class)->search('quoi'))->toHaveCount(1);
});

test('the same search is not asked twice', function () {
    fakeRegistry([['source' => 'a/b', 'skillId' => 'c', 'name' => 'c', 'installs' => 1]]);

    app(SkillRegistry::class)->search('obsidian');
    app(SkillRegistry::class)->search('Obsidian ');

    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/api/search')))->toHaveCount(1);
});

/**
 * La description ne vient pas de l'API — elle n'en a pas. `skills use` imprime
 * le SKILL.md sans installer : c'est le seul moyen de savoir ce qu'un skill
 * fait avant de le prendre.
 */
test('a description is read from the skill own frontmatter, without installing it', function () {
    Process::fake(['*' => Process::result(output: <<<'TXT'
        You are being given a Skill to execute.

        <SKILL.md>
        ---
        name: obsidian-cli
        description: Interact with Obsidian vaults using the Obsidian CLI.
        ---

        # Obsidian CLI
        TXT)]);

    $read = app(SkillRegistry::class)->describe('kepano/obsidian-skills', 'obsidian-cli');

    expect($read['description'])->toBe('Interact with Obsidian vaults using the Obsidian CLI.')
        ->and($read['note'])->toBeNull();

    Process::assertRan(fn ($process) => in_array('use', $process->command, true)
        && in_array('kepano/obsidian-skills@obsidian-cli', $process->command, true));
});

/**
 * Vu en vrai : le registre proposait `mattpocock/skills@obsidian-vault`, qui a
 * quitté son dépôt depuis. Dire « il n'a pas de description » serait faux — et
 * on irait l'installer pour rien.
 */
test('a stale registry entry is named for what it is', function () {
    Process::fake(['*' => Process::result(
        output: 'No matching skill found for: obsidian-vault
Available skills:
  - tdd',
        exitCode: 1,
    )]);

    $read = app(SkillRegistry::class)->describe('mattpocock/skills', 'obsidian-vault');

    expect($read['description'])->toBeNull()
        ->and($read['note'])->toContain("n'est plus dans son dépôt");
});

test('a skill without a description says that, and nothing more', function () {
    Process::fake(['*' => Process::result(output: '---
name: muet
---

# Muet')]);

    $read = app(SkillRegistry::class)->describe('a/b', 'muet');

    expect($read['description'])->toBeNull()
        ->and($read['note'])->toContain('ne porte pas de description');
});

test('a CLI that does not answer is not mistaken for a skill that says nothing', function () {
    Process::fake(['*' => Process::result(output: 'npm ERR!', exitCode: 127)]);

    expect(app(SkillRegistry::class)->describe('a/b', 'c')['note'])->toContain("n'a pas répondu");
});

test('a repository name that is not one never reaches the shell', function () {
    Process::fake();

    expect(app(SkillRegistry::class)->describe('rm -rf /', 'c')['description'])->toBeNull()
        ->and(app(SkillRegistry::class)->describe('a/b', 'c; rm -rf /')['description'])->toBeNull();

    Process::assertNothingRan();
});

/**
 * Régression, vue en vrai : après un déploiement qui change la forme de ce
 * qu'on met en cache, les entrées tièdes portent encore l'ancienne. Ici une
 * chaîne là où on attend un tableau — et la page tombait en TypeError. Les
 * tests partent d'un cache vide ; un vrai poste, non.
 */
test('a cached value from an older shape is thrown away, not returned', function () {
    Cache::put('missions.registry.description.'.md5('a/b@c'), 'une description de l\'ancienne forme');

    Process::fake(['*' => Process::result(output: "---\nname: c\ndescription: La bonne.\n---\n")]);

    expect(app(SkillRegistry::class)->describe('a/b', 'c')['description'])->toBe('La bonne.');
});

test('the installed catalogue distrusts a cache of the old object shape too', function () {
    Cache::put('missions.skills', [new RegistrySkill('a/b', 'c', 'c', 1)]);

    expect(app(SkillCatalog::class)->all())->toBe([]);
});
