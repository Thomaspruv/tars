<?php

use App\Enums\GitSyncStatus;
use App\Models\BrainDocument;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitPullResult;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/brain-vault-test-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath);
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(function () {
    File::deleteDirectory($this->vaultPath);
});

test('does nothing when the vault is not configured', function () {
    config(['brain.remote_url' => null]);

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldNotReceive('ensureCloned');
        $mock->shouldNotReceive('pullRebase');
    });

    $this->artisan('brain:sync')->assertExitCode(0);
});

test('pulls and reindexes on a successful sync, and records the vault status', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned')->once();
        $mock->shouldReceive('pullRebase')->once()->andReturn(new GitPullResult(successful: true, message: 'Already up to date.'));
        // La synchro vient de parler au remote : c'est là que l'état est
        // constaté, pour que les écrans n'aient plus qu'à le relire.
        $mock->shouldReceive('status')->once()->andReturn(GitSyncStatus::Synced);
        $mock->shouldReceive('lastPushAt')->once()->andReturn(now()->subHour());
    });

    $this->artisan('brain:sync')->assertExitCode(0);

    $settings = new BrainSettings;

    expect(BrainDocument::where('path', 'note.md')->exists())->toBeTrue()
        ->and($settings->lastSyncedAt())->not->toBeNull()
        ->and($settings->syncStatus())->toBe(GitSyncStatus::Synced)
        ->and($settings->syncStatusAt())->not->toBeNull()
        ->and($settings->lastPushAt()?->toDateTimeString())->toBe(now()->subHour()->toDateTimeString());
});

test('fails without reindexing when the pull fails, and says since when', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned')->once();
        $mock->shouldReceive('pullRebase')->once()->andReturn(new GitPullResult(successful: false, message: 'network error'));
    });

    $this->artisan('brain:sync')->assertExitCode(1);

    $failure = (new BrainSettings)->syncFailure();

    expect(BrainDocument::where('path', 'note.md')->exists())->toBeFalse()
        ->and((new BrainSettings)->lastSyncedAt())->toBeNull()
        ->and((new BrainSettings)->syncStatus())->toBe(GitSyncStatus::Unknown)
        ->and($failure)->not->toBeNull()
        ->and($failure['message'])->toBe('network error')
        ->and($failure['attempts'])->toBe(1);
});

/**
 * Le piège qui a gelé le vault trois mois : `pull --ff-only` refuse dès qu'un
 * commit local existe — et Tars en crée un à chaque écriture. La synchro doit
 * donc rebaser, comme VaultWriter le fait avant chaque écriture.
 */
test('the periodic sync rebases, so a local commit does not freeze the vault forever', function () {
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned')->once();
        $mock->shouldNotReceive('pull');
        $mock->shouldReceive('pullRebase')->once()->andReturn(new GitPullResult(successful: true, message: 'rebased'));
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced);
        $mock->shouldReceive('lastPushAt')->andReturnNull();
    });

    $this->artisan('brain:sync')->assertExitCode(0);
});

test('a failure streak keeps the date of the first failure and counts the attempts', function () {
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned');
        $mock->shouldReceive('pullRebase')->andReturn(new GitPullResult(successful: false, message: 'Not possible to fast-forward'));
    });

    $this->travelTo('2026-07-25 21:08:00');
    $this->artisan('brain:sync')->assertExitCode(1);

    $this->travelTo('2026-10-23 08:00:00');
    $this->artisan('brain:sync')->assertExitCode(1);
    $this->artisan('brain:sync')->assertExitCode(1);

    $failure = (new BrainSettings)->syncFailure();

    // La date est celle du premier échec : c'est elle qui dit depuis quand le
    // vault est figé, pas celle du dernier essai.
    expect($failure['since']->toDateString())->toBe('2026-07-25')
        ->and($failure['attempts'])->toBe(3);
});

test('a successful sync clears the failure, and a warning does not count as one', function () {
    (new BrainSettings)->recordSyncFailure('Not possible to fast-forward');

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned')->once();
        // Un remote injoignable ou des modifications non committées : le rebase
        // n'a rien réécrit, donc la synchro n'est pas en panne — c'est un
        // avertissement.
        $mock->shouldReceive('pullRebase')->once()->andReturn(new GitPullResult(
            successful: true,
            message: 'cannot pull with rebase',
            warning: "Le vault n'a pas pu être mis à jour avant l'écriture.",
        ));
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced);
        $mock->shouldReceive('lastPushAt')->andReturnNull();
    });

    $this->artisan('brain:sync')->assertExitCode(0);

    $settings = new BrainSettings;

    expect($settings->syncFailure())->toBeNull()
        ->and($settings->lastSyncWarning())->toContain("n'a pas pu être mis à jour");
});

test('scheduled runs stay quiet when auto-index is disabled', function () {
    config(['brain.auto_index' => false]);

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldNotReceive('pullRebase');
    });

    $this->artisan('brain:sync --scheduled')->assertExitCode(0);
});

test('scheduled runs stay quiet before the configured frequency has elapsed', function () {
    (new BrainSettings)->markSynced();

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldNotReceive('pullRebase');
    });

    $this->artisan('brain:sync --scheduled')->assertExitCode(0);
});

test('manual runs bypass the frequency throttle and the auto-index toggle', function () {
    config(['brain.auto_index' => false]);
    (new BrainSettings)->markSynced();

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned')->once();
        $mock->shouldReceive('pullRebase')->once()->andReturn(new GitPullResult(successful: true, message: 'ok'));
        $mock->shouldReceive('status')->andReturn(GitSyncStatus::Synced);
        $mock->shouldReceive('lastPushAt')->andReturnNull();
    });

    $this->artisan('brain:sync')->assertExitCode(0);
});
