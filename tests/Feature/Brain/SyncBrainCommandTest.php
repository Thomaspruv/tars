<?php

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
        $mock->shouldNotReceive('pull');
    });

    $this->artisan('brain:sync')->assertExitCode(0);
});

test('pulls and reindexes on a successful sync', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned')->once();
        $mock->shouldReceive('pull')->once()->andReturn(new GitPullResult(successful: true, message: 'Already up to date.'));
    });

    $this->artisan('brain:sync')->assertExitCode(0);

    expect(BrainDocument::where('path', 'note.md')->exists())->toBeTrue()
        ->and((new BrainSettings)->lastSyncedAt())->not->toBeNull();
});

test('fails without reindexing when the pull fails', function () {
    File::put($this->vaultPath.'/note.md', "# Une note\n\nContenu.");

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned')->once();
        $mock->shouldReceive('pull')->once()->andReturn(new GitPullResult(successful: false, message: 'network error'));
    });

    $this->artisan('brain:sync')->assertExitCode(1);

    expect(BrainDocument::where('path', 'note.md')->exists())->toBeFalse()
        ->and((new BrainSettings)->lastSyncedAt())->toBeNull();
});

test('scheduled runs stay quiet when auto-index is disabled', function () {
    config(['brain.auto_index' => false]);

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldNotReceive('pull');
    });

    $this->artisan('brain:sync --scheduled')->assertExitCode(0);
});

test('scheduled runs stay quiet before the configured frequency has elapsed', function () {
    (new BrainSettings)->markSynced();

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldNotReceive('pull');
    });

    $this->artisan('brain:sync --scheduled')->assertExitCode(0);
});

test('manual runs bypass the frequency throttle and the auto-index toggle', function () {
    config(['brain.auto_index' => false]);
    (new BrainSettings)->markSynced();

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('ensureCloned')->once();
        $mock->shouldReceive('pull')->once()->andReturn(new GitPullResult(successful: true, message: 'ok'));
    });

    $this->artisan('brain:sync')->assertExitCode(0);
});
