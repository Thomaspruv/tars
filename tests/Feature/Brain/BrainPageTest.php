<?php

use App\Enums\GitSyncStatus;
use App\Models\BrainDocument;
use App\Models\Entity;
use App\Models\User;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/brain-vault-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('guests are redirected to the login page', function () {
    $this->get(route('brain.index'))->assertRedirect(route('login'));
});

test('shows an empty state when the vault is not configured', function () {
    config(['brain.remote_url' => null]);
    $this->actingAs(User::factory()->create());

    $this->get(route('brain.index'))->assertSee('Vault non configuré');
});

test('browses the indexed document tree by folder and filename', function () {
    $this->actingAs(User::factory()->create());
    BrainDocument::factory()->create(['path' => 'notes/reunion.md', 'title' => 'Réunion SARL Alpha']);

    $this->get(route('brain.index'))
        ->assertSee('notes')
        ->assertSee('reunion.md');
});

test('selects a document and renders its markdown content', function () {
    $this->actingAs(User::factory()->create());
    $document = BrainDocument::factory()->create([
        'path' => 'notes/reunion.md',
        'title' => 'Réunion',
        'content' => "# Réunion\n\nDécisions prises.",
    ]);

    Livewire::test('pages::brain')
        ->call('selectDocument', $document->path)
        ->assertSee('Décisions prises');
});

test('resolves an anchored wikilink to the entity page', function () {
    $this->actingAs(User::factory()->create());
    $entity = Entity::factory()->create(['name' => 'SARL Alpha']);
    $document = BrainDocument::factory()->create([
        'path' => 'notes/reunion.md',
        'content' => 'Vu [[SARL Alpha]] aujourd\'hui.',
    ]);

    Livewire::test('pages::brain')
        ->call('selectDocument', $document->path)
        ->assertSee(route('entities.show', $entity), false);
});

test('searches documents by title or content', function () {
    $this->actingAs(User::factory()->create());
    BrainDocument::factory()->create(['title' => 'Réunion SARL Alpha', 'content' => 'contenu']);
    BrainDocument::factory()->create(['title' => 'Autre chose', 'content' => 'sans rapport']);

    Livewire::test('pages::brain')
        ->set('search', 'sarl alpha')
        ->assertSee('Réunion SARL Alpha')
        ->assertDontSee('Autre chose');
});

test('editing and saving writes the file, commits, and reindexes', function () {
    $this->actingAs(User::factory()->create());
    File::put($this->vaultPath.'/note.md', "# Titre\n\nAncien contenu.");

    $this->artisan('brain:index');
    $document = BrainDocument::where('path', 'note.md')->firstOrFail();

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced);
        $mock->shouldReceive('commit')->once()->with($this->vaultPath, 'note.md', 'tars: edit note.md');
    });

    Livewire::test('pages::brain')
        ->call('selectDocument', 'note.md')
        ->call('startEditing')
        ->set('editContent', "# Titre\n\nNouveau contenu.")
        ->call('save');

    expect(File::get($this->vaultPath.'/note.md'))->toContain('Nouveau contenu')
        ->and(BrainDocument::where('path', 'note.md')->value('content'))->toContain('Nouveau contenu');
});

test('refuses to save when the vault has a git conflict', function () {
    $this->actingAs(User::factory()->create());
    File::put($this->vaultPath.'/note.md', "# Titre\n\nAncien contenu.");

    $this->artisan('brain:index');

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Conflict);
        $mock->shouldNotReceive('commit');
    });

    Livewire::test('pages::brain')
        ->call('selectDocument', 'note.md')
        ->call('startEditing')
        ->set('editContent', 'Contenu modifié.')
        ->call('save')
        ->assertHasErrors(['save']);

    expect(File::get($this->vaultPath.'/note.md'))->toContain('Ancien contenu');
});

test('creates a note, commits it, and indexes it', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('commit')->once()->with($this->vaultPath, 'nouvelle-note.md', 'tars: create nouvelle-note.md');
    });

    Livewire::test('pages::brain')
        ->call('openCreateModal')
        ->set('newTitle', 'Nouvelle note')
        ->call('createNote')
        ->assertSet('showCreateModal', false)
        ->assertSet('path', 'nouvelle-note.md');

    expect(File::exists($this->vaultPath.'/nouvelle-note.md'))->toBeTrue()
        ->and(BrainDocument::where('path', 'nouvelle-note.md')->exists())->toBeTrue();
});
