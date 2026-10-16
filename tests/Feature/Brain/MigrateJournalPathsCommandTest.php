<?php

use App\Models\JournalEntry;
use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/migrate-journal-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath.'/TARS/Journal/2026');
    File::ensureDirectoryExists($this->vaultPath.'/TARS/Journal/Bilans');
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    Queue::fake();
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('moves the journal and the bilans into the target tree in a single commit', function () {
    File::put($this->vaultPath.'/TARS/Journal/2026/2026-07-28.md', "---\nschema: 3\ntype: journal\ntitle: Journal du 2026-07-28\ndate: 2026-07-28\n---\n\n## Résumé\nJournée dense.\n");
    File::put($this->vaultPath.'/TARS/Journal/Bilans/2026-07-01 — Retro perso.md', "---\nschema: 3\ntype: journal\ntitle: Retro perso\ndate: 2026-07-01\n---\n\n## Satisfaction\n8\n");

    $entry = JournalEntry::factory()->create(['date' => '2026-07-28', 'note_path' => 'TARS/Journal/2026/2026-07-28.md']);
    $questionnaire = Questionnaire::factory()->create(['name' => 'Retro perso']);
    $run = QuestionnaireRun::factory()->create([
        'questionnaire_id' => $questionnaire->id,
        'due_date' => '2026-07-01',
        'note_path' => 'TARS/Journal/Bilans/2026-07-01 — Retro perso.md',
    ]);

    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths')->once()->with(
        Mockery::type('string'),
        Mockery::type('array'),
        'tars: journal migration vers Journal/',
    ));

    $this->artisan('brain:migrate-journal')->assertExitCode(0);

    expect(File::exists($this->vaultPath.'/Journal/2026/2026-07-28.md'))->toBeTrue()
        ->and(File::get($this->vaultPath.'/Journal/2026/2026-07-28.md'))->toContain('Journée dense')
        ->and(File::exists($this->vaultPath.'/TARS/Journal/2026/2026-07-28.md'))->toBeFalse()
        ->and(File::exists($this->vaultPath.'/Journal/Bilans/2026-07-01 — Retro perso.md'))->toBeTrue()
        ->and($entry->fresh()->note_path)->toBe('Journal/2026/2026-07-28.md')
        ->and($run->fresh()->note_path)->toBe('Journal/Bilans/2026-07-01 — Retro perso.md');
});

test('rewrites the pointer even when the file has already left the vault', function () {
    $entry = JournalEntry::factory()->create(['date' => '2026-07-29', 'note_path' => 'TARS/Journal/2026/2026-07-29.md']);

    mockVaultGit(fn ($mock) => $mock->shouldNotReceive('commitPaths'));

    $this->artisan('brain:migrate-journal')
        ->expectsOutputToContain('fichier absent du vault')
        ->assertExitCode(0);

    expect($entry->fresh()->note_path)->toBe('Journal/2026/2026-07-29.md');
});

test('--dry-run writes nothing', function () {
    File::put($this->vaultPath.'/TARS/Journal/2026/2026-07-28.md', "---\nschema: 3\ntype: journal\ntitle: J\ndate: 2026-07-28\n---\n\nTexte.\n");
    $entry = JournalEntry::factory()->create(['date' => '2026-07-28', 'note_path' => 'TARS/Journal/2026/2026-07-28.md']);

    mockVaultGit(fn ($mock) => $mock->shouldNotReceive('commitPaths'));

    $this->artisan('brain:migrate-journal --dry-run')->assertExitCode(0);

    expect($entry->fresh()->note_path)->toBe('TARS/Journal/2026/2026-07-28.md')
        ->and(File::exists($this->vaultPath.'/TARS/Journal/2026/2026-07-28.md'))->toBeTrue()
        ->and(File::exists($this->vaultPath.'/Journal/2026/2026-07-28.md'))->toBeFalse();
});

test('is idempotent: a second pass has nothing to do', function () {
    JournalEntry::factory()->create(['date' => '2026-07-28', 'note_path' => 'Journal/2026/2026-07-28.md']);

    mockVaultGit(fn ($mock) => $mock->shouldNotReceive('commitPaths'));

    $this->artisan('brain:migrate-journal')
        ->expectsOutputToContain('déjà dans l\'arborescence cible')
        ->assertExitCode(0);
});

test('stays quiet when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    $this->artisan('brain:migrate-journal')->assertExitCode(0);
});
