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

test('confirms the vault configuration save with an inline message', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('brainSyncFrequency', 30)
        ->call('saveBrainSettings')
        ->assertSee('✓ Enregistré');
});

test('does not confirm the vault configuration save when validation fails', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('brainSyncFrequency', 0)
        ->call('saveBrainSettings')
        ->assertDontSee('✓ Enregistré');
});

test('tests the git connection', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('lsRemote')->with('git@example.com:vault.git')->once()->andReturnTrue();
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Unknown);
        $mock->shouldReceive('lastPushAt')->andReturnNull();
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
        $mock->shouldReceive('lastPushAt')->andReturnNull();
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
        $mock->shouldReceive('lastPushAt')->andReturnNull();
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

test('shows the last push, the last push error and the conflict alert', function () {
    $this->actingAs(User::factory()->create());
    app(BrainSettings::class)->recordPushError('rejected: non-fast-forward');
    app(BrainSettings::class)->recordConflict('Conflit git — le contenu a été gardé dans TARS/2026-10-16-conflit-alpha.md.');

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced);
        $mock->shouldReceive('lastPushAt')->andReturn(now()->subMinutes(3));
    });

    Livewire::test('pages::settings-app')
        ->assertSee('Dernier push')
        ->assertSee('Conflit git')
        ->assertSee('TARS/2026-10-16-conflit-alpha.md')
        ->assertSee('rejected: non-fast-forward');
});

test('forcing the sync pull-rebases then pushes', function () {
    $this->actingAs(User::factory()->create());
    app(BrainSettings::class)->recordPushError('une vieille erreur');

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced);
        $mock->shouldReceive('lastPushAt')->andReturn(null);
        $mock->shouldReceive('pullRebase')->once()->andReturn(new GitPullResult(successful: true, message: 'Successfully rebased.'));
        $mock->shouldReceive('push')->once();
    });

    Livewire::test('pages::settings-app')
        ->call('forceBrainSync')
        ->assertSee('Vault synchronisé');

    expect(app(BrainSettings::class)->lastPushError())->toBeNull();
});

test('forcing the sync reports a conflict instead of pushing', function () {
    $this->actingAs(User::factory()->create());

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Conflict);
        $mock->shouldReceive('lastPushAt')->andReturn(null);
        $mock->shouldReceive('pullRebase')->once()->andReturn(new GitPullResult(successful: false, message: 'CONFLICT (content)'));
        $mock->shouldNotReceive('push');
    });

    Livewire::test('pages::settings-app')
        ->call('forceBrainSync')
        ->assertSee('Le pull-rebase a échoué');
});

test('shows why the vault was not up to date on the last write', function () {
    $this->actingAs(User::factory()->create());
    app(BrainSettings::class)->recordSyncWarning("Le vault n'a pas pu être mis à jour avant l'écriture : des modifications ne sont pas encore committées dans le dépôt local.");

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced);
        $mock->shouldReceive('lastPushAt')->andReturnNull();
    });

    Livewire::test('pages::settings-app')->assertSee('pas encore committées');
});
