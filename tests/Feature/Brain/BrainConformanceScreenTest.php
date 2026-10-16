<?php

use App\Models\BrainDocument;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/brain-conformance-screen-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    $this->actingAs(User::factory()->create());
    mockVaultGit(fn ($mock) => $mock->shouldReceive('commitPaths'));
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('badges a non conformant note and spells out what the contract expects', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    File::put($this->vaultPath.'/Notes/marc.md', "---\ntype: insight\n---\n\nMarc Dupont.");
    $this->artisan('brain:index');

    Livewire::test('pages::brain')
        ->assertSet('nonConformantPaths', ['Notes/marc.md'])
        ->call('selectDocument', 'Notes/marc.md')
        ->assertSee('non conforme')
        ->assertSee('Type `insight` inconnu')
        ->assertSee('`title` manquant');
});

test('never badges Profil, Hermes territory or a generated file', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Profil');
    File::put($this->vaultPath.'/Profil/perso.md', "---\ntype: profil\n---\n\nQui je suis.");
    $this->artisan('brain:index');

    Livewire::test('pages::brain')
        ->assertSet('nonConformantPaths', [])
        ->call('selectDocument', 'Profil/perso.md')
        ->assertDontSee('non conforme');
});

test('creating a note shows the path TARS computed before saving', function () {
    Livewire::test('pages::brain')
        ->call('openCreateModal')
        ->set('newType', 'person')
        ->set('newTitle', 'Marc Dupont')
        ->assertSee('Personnes/marc-dupont.md')
        ->call('createNote')
        ->assertHasNoErrors()
        ->assertSet('path', 'Personnes/marc-dupont.md');

    $file = File::get($this->vaultPath.'/Personnes/marc-dupont.md');

    expect($file)->toContain('schema: 3')
        ->toContain('type: person')
        ->toContain('source: thomas')
        ->and(BrainDocument::where('path', 'Personnes/marc-dupont.md')->first()->is_conformant)->toBeTrue();
});

test('a dated note type shows a dated path', function () {
    Livewire::test('pages::brain')
        ->call('openCreateModal')
        ->set('newType', 'meeting')
        ->set('newTitle', 'Point Alpha')
        ->assertSee('Réunions/'.now()->toDateString().'-point-alpha.md');
});

test('refuses to create a living note where one already exists', function () {
    Livewire::test('pages::brain')
        ->call('openCreateModal')
        ->set('newType', 'person')
        ->set('newTitle', 'Marc Dupont')
        ->call('createNote')
        ->assertHasNoErrors();

    Livewire::test('pages::brain')
        ->call('openCreateModal')
        ->set('newType', 'person')
        ->set('newTitle', 'Marc Dupont')
        ->call('createNote')
        ->assertHasErrors(['newTitle']);
});

test('Thomas can save a non conformant note, and is told what it costs', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    File::put($this->vaultPath.'/Notes/alpha.md', "---\nschema: 3\ntype: note\ntitle: Alpha\ndate: 2026-10-16\n---\n\nConforme.");
    $this->artisan('brain:index');

    Livewire::test('pages::brain')
        ->call('selectDocument', 'Notes/alpha.md')
        ->call('startEditing')
        ->set('editContent', "---\ntype: insight\n---\n\nRemanié à la main.")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Enregistré, mais non conforme')
        ->assertSee('ancres de cette note ne seront pas résolues');

    // The save went through — the contract binds agents, not Thomas.
    expect(File::get($this->vaultPath.'/Notes/alpha.md'))->toContain('Remanié à la main')
        ->and(BrainDocument::where('path', 'Notes/alpha.md')->first()->is_conformant)->toBeFalse();
});

test('saving a conformant note says nothing', function () {
    File::ensureDirectoryExists($this->vaultPath.'/Notes');
    File::put($this->vaultPath.'/Notes/alpha.md', "---\nschema: 3\ntype: note\ntitle: Alpha\ndate: 2026-10-16\n---\n\nConforme.");
    $this->artisan('brain:index');

    Livewire::test('pages::brain')
        ->call('selectDocument', 'Notes/alpha.md')
        ->call('startEditing')
        ->set('editContent', "---\nschema: 3\ntype: note\ntitle: Alpha\ndate: 2026-10-16\n---\n\nRevu.")
        ->call('save')
        ->assertSet('saveMessage', null)
        ->assertDontSee('non conforme');
});
