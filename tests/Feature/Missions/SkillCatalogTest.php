<?php

use App\Support\Missions\Skill;
use App\Support\Missions\SkillCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

function makeSkill(string $root, string $slug, string $description, ?string $name = null): void
{
    File::ensureDirectoryExists($root.'/'.$slug);
    File::put($root.'/'.$slug.'/SKILL.md', <<<MD
        ---
        name: {$name}
        description: "{$description}"
        ---

        # {$slug}
        MD);
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/tars-skills-'.uniqid();

    config()->set('missions.skill_paths', [
        'projet' => $this->root.'/projet',
        'global' => $this->root.'/global',
    ]);
    config()->set('missions.skill_lock_path', $this->root.'/.skill-lock.json');

    app(SkillCatalog::class)->forget();
});

afterEach(fn () => File::deleteDirectory($this->root));

test('reads name and description from the skill frontmatter', function () {
    makeSkill($this->root.'/projet', 'pest-testing', 'Pour tester avec Pest.', 'pest-testing');

    $skills = app(SkillCatalog::class)->all();

    expect($skills)->toHaveCount(1)
        ->and($skills[0]->slug)->toBe('pest-testing')
        ->and($skills[0]->description)->toBe('Pour tester avec Pest.')
        ->and($skills[0]->scope)->toBe('projet');
});

test('a project skill hides its global namesake, as claude code resolves them', function () {
    makeSkill($this->root.'/projet', 'doublon', 'La version du projet.');
    makeSkill($this->root.'/global', 'doublon', 'La version globale.');
    makeSkill($this->root.'/global', 'ailleurs', 'Seulement globale.');

    $skills = collect(app(SkillCatalog::class)->all())->keyBy('slug');

    expect($skills)->toHaveCount(2)
        ->and($skills['doublon']->scope)->toBe('projet')
        ->and($skills['doublon']->description)->toBe('La version du projet.')
        ->and($skills['ailleurs']->scope)->toBe('global');
});

test('provenance comes from the skills.sh lock file', function () {
    makeSkill($this->root.'/global', 'find-skills', 'Trouve des skills.');

    File::put($this->root.'/.skill-lock.json', (string) json_encode([
        'skills' => [
            'find-skills' => [
                'source' => 'vercel-labs/skills',
                'sourceUrl' => 'https://github.com/vercel-labs/skills.git',
                'updatedAt' => '2026-10-14T15:23:34.973Z',
            ],
        ],
    ]));

    $skill = app(SkillCatalog::class)->find('find-skills');

    expect($skill?->source)->toBe('vercel-labs/skills')
        ->and($skill?->updatedAt)->toBe('2026-10-14T15:23:34.973Z');
});

test('a directory without a SKILL.md is not a skill, and a broken frontmatter is tolerated', function () {
    File::ensureDirectoryExists($this->root.'/projet/pas-un-skill');
    File::put($this->root.'/projet/pas-un-skill/README.md', 'rien');
    File::ensureDirectoryExists($this->root.'/projet/casse');
    File::put($this->root.'/projet/casse/SKILL.md', "---\n\tname: [non\n---\n\n# casse");

    $skills = app(SkillCatalog::class)->all();

    // Un skill mal écrit reste listé sous son dossier : on ne fait pas
    // disparaître de l'écran quelque chose que Claude Code chargera quand même.
    expect(array_map(fn ($skill) => $skill->slug, $skills))->toBe(['casse']);
});

test('a missing skill directory is not an error', function () {
    expect(app(SkillCatalog::class)->all())->toBe([])
        ->and(app(SkillCatalog::class)->find('absent'))->toBeNull();
});

test('a package name must look like a github repository', function () {
    expect(SkillCatalog::isValidPackage('anthropics/skills'))->toBeTrue()
        ->and(SkillCatalog::isValidPackage('vercel-labs/skills'))->toBeTrue()
        ->and(SkillCatalog::isValidPackage('skills'))->toBeFalse()
        ->and(SkillCatalog::isValidPackage('../../etc/passwd'))->toBeFalse()
        ->and(SkillCatalog::isValidPackage('a/b; rm -rf /'))->toBeFalse();
});

test('a long description is cut for the screen but kept whole underneath', function () {
    makeSkill($this->root.'/projet', 'bavard', str_repeat('mot ', 100));

    $skill = app(SkillCatalog::class)->find('bavard');

    expect(mb_strlen((string) $skill?->description))->toBeGreaterThan(300)
        ->and($skill?->shortDescription(50))->toEndWith('…')
        ->and(mb_strlen((string) $skill?->shortDescription(50)))->toBe(51);
});

/**
 * Régression : le catalogue mettait des objets `Skill` en cache. Relus par le
 * worker de queue, ils revenaient en objets incomplets — et la mission
 * échouait avant d'avoir lancé quoi que ce soit. Le cache ne porte que des
 * données.
 */
test('the cache holds plain data, so another process can read it back', function () {
    makeSkill($this->root.'/projet', 'pest-testing', 'Pour tester avec Pest.');

    expect(app(SkillCatalog::class)->all()[0])->toBeInstanceOf(Skill::class);

    $cached = Cache::get('missions.skills');

    expect($cached)->toBeArray()
        ->and($cached[0])->toBeArray()
        ->and($cached[0]['slug'])->toBe('pest-testing');

    // Et le tour de cache rend bien des objets, pas les tableaux bruts.
    expect(app(SkillCatalog::class)->find('pest-testing')?->description)->toBe('Pour tester avec Pest.');
});
