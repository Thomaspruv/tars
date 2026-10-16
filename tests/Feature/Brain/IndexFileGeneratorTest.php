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

test('writes the README and one index per structuring folder', function () {
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once()->with(
        Mockery::type('string'),
        Mockery::on(fn (array $paths): bool => in_array('README.md', $paths, true) && in_array('Personnes/_index.md', $paths, true)),
        'tars: index',
    ));

    $result = app(IndexFileGenerator::class)->write();

    expect($result->isWritten())->toBeTrue()
        ->and($result->paths)->toContain('README.md', 'Projets/_index.md', 'Personnes/_index.md', 'Organisations/_index.md', 'Veille/_index.md', 'Skills/_index.md')
        ->and(File::get($this->vaultPath.'/README.md'))->toContain('Le vault — la seconde mémoire de Thomas')
        ->and(File::get($this->vaultPath.'/README.md'))->toContain('Maintenu par TARS et Thomas')
        ->and(File::get($this->vaultPath.'/Personnes/_index.md'))->toContain('Généré par TARS')
        ->and(File::get($this->vaultPath.'/Personnes/_index.md'))->toContain("Aucune note pour l'instant");
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
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));

    app(IndexFileGenerator::class)->write();

    BrainDocument::factory()->create(['path' => 'Personnes/marc.md', 'title' => 'Marc', 'note_type' => 'person']);

    expect(array_map(
        fn ($operation): string => $operation->relativePath,
        app(IndexFileGenerator::class)->operations()
    ))->toBe(['Personnes/_index.md']);
});

test('the generated files are themselves conformant and exempt from the folder check', function () {
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once());

    app(IndexFileGenerator::class)->write();

    $readme = BrainDocument::where('path', 'README.md')->firstOrFail();

    expect($readme->is_conformant)->toBeTrue()
        ->and($readme->isConformanceExempt())->toBeTrue();
});

test('the write-index command stays quiet when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    $this->artisan('brain:write-index --scheduled')->assertExitCode(0);

    expect(File::exists($this->vaultPath.'/README.md'))->toBeFalse();
});

test('the index command reposes the README right after indexing', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once());

    $this->artisan('brain:index')->assertExitCode(0);

    expect(File::exists($this->vaultPath.'/README.md'))->toBeTrue();
});
