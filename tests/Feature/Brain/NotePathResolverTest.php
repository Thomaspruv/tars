<?php

use App\Support\Brain\NotePathResolver;
use App\Support\Brain\Schema\NoteType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/note-path-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

/**
 * A-P0.1: an agent that only read the vault's README must land a note of
 * type X in the right place — ten types, ten correct paths.
 */
test('computes the contractual path for every type', function (string $type, string $expected, array $options) {
    $path = app(NotePathResolver::class)->resolve(
        NoteType::from($type),
        'Point HubSpot avec Marc',
        Carbon::parse('2026-10-16'),
        $options,
    );

    expect($path)->toBe($expected);
})->with([
    ['person', 'Personnes/point-hubspot-avec-marc.md', []],
    ['org', 'Organisations/point-hubspot-avec-marc.md', []],
    ['note', 'Notes/point-hubspot-avec-marc.md', []],
    ['meeting', 'Réunions/2026-10-16-point-hubspot-avec-marc.md', []],
    ['decision', 'Décisions/2026-10-16-point-hubspot-avec-marc.md', []],
    ['journal', 'Journal/2026/2026-10-16.md', []],
    ['veille', 'Veille/2026-10-16-point-hubspot-avec-marc.md', []],
    ['veille', 'Veille/agents-ia/2026-10-16-point-hubspot-avec-marc.md', ['subject' => 'Agents IA']],
    ['a-traiter', 'TARS/point-hubspot-avec-marc.md', []],
    ['skill', 'Skills/point-hubspot-avec-marc.md', []],
    ['project', 'Projets/point-hubspot-avec-marc/README.md', []],
    ['meeting', 'Projets/spreak-hubspot/notes/2026-10-16-point-hubspot-avec-marc.md', ['project' => 'spreak-hubspot']],
]);

test('a living note keeps its path so a second write is an update', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Personnes');
    File::put($this->vaultPath.'/Personnes/marc-dupont.md', 'Déjà là.');

    expect(app(NotePathResolver::class)->resolve(NoteType::Person, 'Marc Dupont'))->toBe('Personnes/marc-dupont.md');
});

test('a second dated note the same day is suffixed instead of overwriting', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Réunions');
    File::put($this->vaultPath.'/Réunions/2026-10-16-point-alpha.md', 'Premier.');

    $resolver = app(NotePathResolver::class);
    $second = $resolver->resolve(NoteType::Meeting, 'Point Alpha', Carbon::parse('2026-10-16'));

    expect($second)->toBe('Réunions/2026-10-16-point-alpha-2.md');

    File::put($this->vaultPath.'/'.$second, 'Deuxième.');

    expect($resolver->resolve(NoteType::Meeting, 'Point Alpha', Carbon::parse('2026-10-16')))
        ->toBe('Réunions/2026-10-16-point-alpha-3.md');
});

test('the journal of a day is always the same file', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Journal/2026');
    File::put($this->vaultPath.'/Journal/2026/2026-10-16.md', 'Déjà écrit.');

    expect(app(NotePathResolver::class)->resolve(NoteType::Journal, 'peu importe', Carbon::parse('2026-10-16')))
        ->toBe('Journal/2026/2026-10-16.md');
});

test('falls back to today and to a usable slug', function () {
    Carbon::setTestNow('2026-10-16 09:00');

    expect(app(NotePathResolver::class)->resolve(NoteType::Meeting, '???'))
        ->toBe('Réunions/2026-10-16-note.md');

    Carbon::setTestNow();
});
