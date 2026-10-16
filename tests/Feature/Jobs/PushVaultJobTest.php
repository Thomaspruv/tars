<?php

use App\Jobs\PushVaultJob;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->vaultPath = sys_get_temp_dir().'/push-job-'.uniqid();
    File::ensureDirectoryExists($this->vaultPath.'/.git');
    config(['brain.local_path' => $this->vaultPath, 'brain.remote_url' => 'git@example.com:vault.git']);
});

afterEach(fn () => File::deleteDirectory($this->vaultPath));

test('pushes the vault and clears any previous push error', function () {
    app(BrainSettings::class)->recordPushError('une vieille erreur');

    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('isRepository')->andReturn(true);
        $mock->shouldReceive('push')->once();
    });

    app(PushVaultJob::class)->handle(app(BrainSettings::class), app(GitRepository::class));

    expect(app(BrainSettings::class)->lastPushError())->toBeNull();
});

test('records the failure and lets the job retry', function () {
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('isRepository')->andReturn(true);
        $mock->shouldReceive('push')->andThrow(new RuntimeException('rejected: non-fast-forward'));
    });

    expect(fn () => app(PushVaultJob::class)->handle(app(BrainSettings::class), app(GitRepository::class)))
        ->toThrow(RuntimeException::class);

    expect(app(BrainSettings::class)->lastPushError())->toContain('non-fast-forward');
});

test('stays quiet when the vault is not a repository', function () {
    $this->mock(GitRepository::class, function ($mock): void {
        $mock->shouldReceive('isRepository')->andReturn(false);
        $mock->shouldNotReceive('push');
    });

    app(PushVaultJob::class)->handle(app(BrainSettings::class), app(GitRepository::class));

    expect(app(BrainSettings::class)->lastPushError())->toBeNull();
});

test('retries three times with a thirty second backoff', function () {
    expect((new PushVaultJob)->tries)->toBe(3)
        ->and((new PushVaultJob)->backoff)->toBe(30);
});
