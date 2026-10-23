<?php

use App\Enums\GitSyncStatus;
use App\Jobs\RefreshVaultStatusJob;
use App\Models\User;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/brain-vault-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('the job records the vault status and the last push', function () {
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('status')->once()->andReturn(GitSyncStatus::Behind);
        $mock->shouldReceive('lastPushAt')->once()->andReturn(now()->subMinutes(42));
    });

    dispatch_sync(new RefreshVaultStatusJob);

    $settings = new BrainSettings;

    expect($settings->syncStatus())->toBe(GitSyncStatus::Behind)
        ->and($settings->syncStatusAt())->not->toBeNull()
        ->and($settings->lastPushAt()?->toDateTimeString())->toBe(now()->subMinutes(42)->toDateTimeString());
});

test('the job leaves the status alone when no vault is configured', function () {
    config(['brain.remote_url' => null]);

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldNotReceive('status');
    });

    dispatch_sync(new RefreshVaultStatusJob);

    expect((new BrainSettings)->syncStatus())->toBe(GitSyncStatus::Unknown);
});

/**
 * Le cœur de l'affaire : `git status` fait un `git fetch`, soit ~1,1 s de
 * réseau pour une page dont tout le reste coûte 20 ms. Le rendu ne doit donc
 * jamais y toucher — il relit ce qui a été constaté.
 */
test('rendering Mémoire reads the recorded status without reaching the remote', function () {
    (new BrainSettings)->recordSyncStatus(GitSyncStatus::Behind, now()->subMinutes(5));

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldNotReceive('status');
        $mock->shouldNotReceive('lastPushAt');
    });

    Livewire::test('pages::brain')
        ->assertSee('Git en retard')
        ->set('search', 'quelque chose')
        ->set('search', 'quelque chose de plus long');
});

test('rendering Réglages reads the recorded status without reaching the remote', function () {
    (new BrainSettings)->recordSyncStatus(GitSyncStatus::Conflict, now()->subMinutes(5));

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldNotReceive('status');
        $mock->shouldNotReceive('lastPushAt');
    });

    Livewire::test('pages::settings-app')->assertSee('Statut vérifié');
});

test('a stale status is refreshed off the request', function () {
    Queue::fake();

    (new BrainSettings)->recordSyncStatus(GitSyncStatus::Synced, null);

    $this->travel(RefreshVaultStatusJob::FRESH_FOR_MINUTES + 1)->minutes();

    Livewire::test('pages::brain');

    Queue::assertPushed(RefreshVaultStatusJob::class, 1);
});

test('a fresh status is left alone, and a page load costs nothing', function () {
    Queue::fake();

    (new BrainSettings)->recordSyncStatus(GitSyncStatus::Synced, null);

    Livewire::test('pages::brain');

    Queue::assertNotPushed(RefreshVaultStatusJob::class);
});

test('no vault, no refresh', function () {
    Queue::fake();
    config(['brain.remote_url' => null]);

    Livewire::test('pages::brain');

    Queue::assertNotPushed(RefreshVaultStatusJob::class);
});

/**
 * Une divergence ne se répare pas d'elle-même : la synchro réessaiera à
 * l'identique toutes les quinze minutes, et le vault restera figé. Le seul
 * indice était un « dernier pull » qui vieillissait dans les Réglages — c'est
 * comme ça qu'on a perdu trois mois sans s'en apercevoir.
 */
test('a frozen sync is said out loud on Mémoire and in Réglages', function () {
    (new BrainSettings)->recordSyncStatus(GitSyncStatus::Behind, null);

    $this->travelTo('2026-07-25 21:08:00');
    (new BrainSettings)->recordSyncFailure('fatal: Not possible to fast-forward, aborting.');
    $this->travelTo('2026-10-23 09:00:00');
    (new BrainSettings)->recordSyncFailure('fatal: Not possible to fast-forward, aborting.');

    Livewire::test('pages::brain')
        ->assertSee('La synchro du vault échoue depuis')
        ->assertSee('2 tentatives')
        ->assertSee('Not possible to fast-forward');

    Livewire::test('pages::settings-app')
        ->assertSee('Synchro en panne depuis')
        ->assertSee('2 tentatives');
});

test('nothing is said when the sync is healthy', function () {
    (new BrainSettings)->recordSyncStatus(GitSyncStatus::Synced, null);

    Livewire::test('pages::brain')->assertDontSee('La synchro du vault échoue');
    Livewire::test('pages::settings-app')->assertDontSee('Synchro en panne');
});
