<?php

use App\Enums\GitSyncStatus;
use App\Models\BrainDocument;
use App\Models\User;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitPullResult;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/brain-vault-test-'.uniqid();
    config(['brain.local_path' => $this->vaultPath]);
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('saves the vault configuration', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('brainRemoteUrl', 'git@example.com:vault.git')
        ->set('brainBranch', 'develop')
        ->set('brainSyncFrequency', 30)
        ->set('brainAutoIndex', false)
        ->call('saveBrainSettings')
        ->assertHasNoErrors();

    $settings = new BrainSettings;

    expect($settings->remoteUrl())->toBe('git@example.com:vault.git')
        ->and($settings->branch())->toBe('develop')
        ->and($settings->syncFrequencyMinutes())->toBe(30)
        ->and($settings->autoIndexEnabled())->toBeFalse();
});

test('requires a valid sync frequency', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('brainSyncFrequency', 0)
        ->call('saveBrainSettings')
        ->assertHasErrors(['brainSyncFrequency']);
});

test('tests the git connection', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('lsRemote')->with('git@example.com:vault.git')->once()->andReturnTrue();
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Unknown);
    });

    Livewire::test('pages::settings-app')
        ->set('brainRemoteUrl', 'git@example.com:vault.git')
        ->call('testBrainConnection')
        ->assertSet('brainTestSuccessful', true)
        ->assertSee('Connexion au dépôt réussie.');
});

test('reports a failed connection test', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('lsRemote')->once()->andReturnFalse();
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Unknown);
    });

    Livewire::test('pages::settings-app')
        ->call('testBrainConnection')
        ->assertSet('brainTestSuccessful', false);
});

test('syncs the vault now and refreshes the status', function () {
    $this->actingAs(User::factory()->create());
    config(['brain.remote_url' => 'git@example.com:vault.git']);
    File::ensureDirectoryExists($this->vaultPath);

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned')->once();
        $mock->shouldReceive('pull')->once()->andReturn(new GitPullResult(successful: true, message: 'ok'));
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced);
    });

    Livewire::test('pages::settings-app')->call('syncBrainNow');

    expect((new BrainSettings)->lastSyncedAt())->not->toBeNull();
});

test('reindexes the vault from scratch', function () {
    $this->actingAs(User::factory()->create());
    config(['brain.remote_url' => 'git@example.com:vault.git']);
    File::ensureDirectoryExists($this->vaultPath);
    File::put($this->vaultPath.'/note.md', "# Note\n\nContenu.");
    BrainDocument::factory()->create(['path' => 'orphan.md']);

    Livewire::test('pages::settings-app')->call('reindexBrainFull');

    expect(BrainDocument::where('path', 'orphan.md')->exists())->toBeFalse()
        ->and(BrainDocument::where('path', 'note.md')->exists())->toBeTrue();
});

test('shows the git status badge', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('settings.index'))->assertSee('Inconnu');
});
