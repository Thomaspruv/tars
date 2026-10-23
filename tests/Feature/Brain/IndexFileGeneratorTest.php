<?php

use App\Jobs\PushVaultJob;
use App\Models\BrainDocument;
use App\Support\Brain\IndexFileGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/index-gen-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('writes one index per folder that actually holds notes', function () {
    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc', 'note_type' => 'person']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once()->with(
        Mockery::type('string'),
        Mockery::on(fn (array $paths): bool => in_array('Personnes/_index.md', $paths, true)),
        'tars: index',
    ));

    $result = app(IndexFileGenerator::class)->write();

    expect($result->isWritten())->toBeTrue()
        ->and($result->paths)->toBe(['Personnes/_index.md'])
        ->and(File::get($this->vaultPath.'/Personnes/_index.md'))->toContain('Généré par TARS');
});

/**
 * Le vault est un cerveau partagé — Thomas dans Obsidian, Hermès, TARS. Sa
 * porte d'entrée appartient donc au vault, pas à l'un de ses rédacteurs.
 * Mesuré : TARS reposait son propre README par-dessus celui d'Hermès toutes les
 * quinze minutes (`brain:index` est planifié), et ce commit local est
 * exactement ce qu'un `pull --ff-only` a ensuite refusé pendant trois mois.
 */
test('never touches the root README, which belongs to the vault', function () {
    File::put($this->vaultPath.'/README.md', "# Le README de quelqu'un d'autre\n");
    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc', 'note_type' => 'person']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once());

    $result = app(IndexFileGenerator::class)->write();

    expect($result->paths)->not->toContain('README.md')
        ->and(File::get($this->vaultPath.'/README.md'))->toBe("# Le README de quelqu'un d'autre\n");
});

/**
 * Inventer les dossiers manquants était la façon dont l'arborescence cible
 * « apparaissait » dans Obsidian. Dans un vault partagé, ça plante cinq
 * dossiers vides à côté de ceux que Thomas et Hermès utilisent déjà — deux
 * taxonomies dans un dépôt, précisément le bordel à éviter.
 */
test('does not invent a folder just to put an empty index in it', function () {
    mockVaultGit(fn ($mock) => $mock->shouldNotReceive('commitPaths'));

    $result = app(IndexFileGenerator::class)->write();

    expect($result->paths)->toBe([])
        ->and(File::exists($this->vaultPath.'/Personnes'))->toBeFalse()
        ->and(File::exists($this->vaultPath.'/Projets'))->toBeFalse();
});

test('lists the documents of a folder with their type, status and freshness', function () {
    BrainDocument::factory()->create([
        'path' => 'Personnes/marc-dupont.md',
        'title' => 'Marc Dupont',
        'note_type' => 'person',
        'updated_on' => '2026-10-16',
    ]);
    BrainDocument::factory()->create([
        'path' => 'Projets/spreak-hubspot/README.md',
        'title' => 'Spreak — HubSpot',
        'note_type' => 'project',
        'status' => 'active',
        'updated_on' => '2026-10-15',
    ]);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once());

    app(IndexFileGenerator::class)->write();

    expect(File::get($this->vaultPath.'/Personnes/_index.md'))
        ->toContain('| Marc Dupont | Personne | — | 2026-10-16 | [marc-dupont.md](marc-dupont.md) |')
        ->and(File::get($this->vaultPath.'/Projets/_index.md'))
        ->toContain('| Spreak — HubSpot | Projet | active | 2026-10-15 | [spreak-hubspot/README.md](spreak-hubspot/README.md) |');
});

test('sorts a folder index by freshness, most recent first', function () {
    BrainDocument::factory()->create(['path' => 'Personnes/ancien.md', 'title' => 'Ancien', 'note_type' => 'person', 'updated_on' => '2026-01-01']);
    BrainDocument::factory()->create(['path' => 'Personnes/recent.md', 'title' => 'Récent', 'note_type' => 'person', 'updated_on' => '2026-10-16']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once());

    app(IndexFileGenerator::class)->write();

    $index = File::get($this->vaultPath.'/Personnes/_index.md');

    expect(strpos($index, 'Récent'))->toBeLessThan(strpos($index, 'Ancien'));
});

test('gives a veille subject and a skill category their own index', function () {
    BrainDocument::factory()->create(['path' => 'Veille/agents-ia/2026-10-16-anthropic.md', 'title' => 'Anthropic', 'note_type' => 'veille', 'status' => 'active']);
    BrainDocument::factory()->create(['path' => 'Skills/redaction/veille/SKILL.md', 'title' => 'Veille', 'note_type' => 'skill']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once());

    $result = app(IndexFileGenerator::class)->write();

    expect($result->paths)->toContain('Veille/agents-ia/_index.md', 'Skills/redaction/_index.md')
        ->and(File::get($this->vaultPath.'/Veille/agents-ia/_index.md'))->toContain('[2026-10-16-anthropic.md]')
        ->and(File::get($this->vaultPath.'/Veille/_index.md'))->toContain('[agents-ia/2026-10-16-anthropic.md]');
});

test('is deterministic: the same index produces the same file', function () {
    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc', 'note_type' => 'person', 'updated_on' => '2026-10-16']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));

    app(IndexFileGenerator::class)->write();
    $first = File::get($this->vaultPath.'/Personnes/_index.md');

    File::delete($this->vaultPath.'/Personnes/_index.md');
    app(IndexFileGenerator::class)->write();

    expect(File::get($this->vaultPath.'/Personnes/_index.md'))->toBe($first);
});

test('commits nothing when nothing changed', function () {
    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc', 'note_type' => 'person']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once());

    app(IndexFileGenerator::class)->write();

    // Second pass: same index, same content — no operation, no commit, no push.
    $result = app(IndexFileGenerator::class)->write();

    expect($result->isWritten())->toBeTrue()
        ->and($result->paths)->toBe([])
        ->and(app(IndexFileGenerator::class)->operations())->toBe([]);

    Queue::assertPushed(PushVaultJob::class, 1);
});

test('rewrites only the index that moved', function () {
    BrainDocument::factory()->create(['path' => 'Projets/spreak/README.md', 'title' => 'Spreak', 'note_type' => 'project']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));

    app(IndexFileGenerator::class)->write();

    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc', 'note_type' => 'person']);

    expect(array_map(
        fn ($operation): string => $operation->relativePath,
        app(IndexFileGenerator::class)->operations()
    ))->toBe(['Personnes/_index.md']);
});

test('the generated files are themselves conformant and exempt from the folder check', function () {
    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc', 'note_type' => 'person']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once());

    app(IndexFileGenerator::class)->write();

    $index = BrainDocument::where('path', 'Personnes/_index.md')->firstOrFail();

    expect($index->is_conformant)->toBeTrue()
        ->and($index->isConformanceExempt())->toBeTrue();
});

test('the write-index command stays quiet when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    $this->artisan('brain:write-index --scheduled')->assertExitCode(0);

    expect(File::exists($this->vaultPath.'/README.md'))->toBeFalse();
});

test('the index command reposes the folder indexes right after indexing, and still not the README', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Personnes');
    File::put($this->vaultPath.'/Personnes/marc.md', "---\nschema: 3\ntype: person\ntitle: Marc\ndate: 2026-10-16\n---\n\n# Marc\n");
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once());

    $this->artisan('brain:index')->assertExitCode(0);

    expect(File::exists($this->vaultPath.'/Personnes/_index.md'))->toBeTrue()
        ->and(File::exists($this->vaultPath.'/README.md'))->toBeFalse();
});

test('rewrites a generated file whose frontmatter drifted', function () {
    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc', 'note_type' => 'person']);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));

    app(IndexFileGenerator::class)->write();

    // Same body, but the contract header was stripped by hand in Obsidian.
    $body = app(IndexFileGenerator::class)->operations();
    expect($body)->toBe([]);

    File::put($this->vaultPath.'/Personnes/_index.md', "# Personnes\n\nSans frontmatter.\n");

    expect(array_map(
        fn ($operation): string => $operation->relativePath,
        app(IndexFileGenerator::class)->operations()
    ))->toBe(['Personnes/_index.md']);
});
