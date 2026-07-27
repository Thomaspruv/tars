<?php

use App\Enums\GitSyncStatus;
use App\Support\Brain\GitRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->path = sys_get_temp_dir().'/brain-test-'.uniqid();
});

afterEach(function () {
    File::deleteDirectory($this->path);
});

test('clones the vault when not already present', function () {
    Process::fake();

    (new GitRepository)->ensureCloned('git@example.com:vault.git', 'main', $this->path);

    Process::assertRan(fn ($process) => $process->command === [
        'git', 'clone', '--branch', 'main', '--single-branch', 'git@example.com:vault.git', $this->path,
    ]);
});

test('does not clone, change the remote, or switch branch when nothing changed', function () {
    File::ensureDirectoryExists($this->path.'/.git');

    Process::fake(fn ($process) => match (true) {
        ($process->command[1] ?? null) === 'remote' && ($process->command[2] ?? null) === 'get-url' => Process::result('git@example.com:vault.git'),
        ($process->command[1] ?? null) === 'rev-parse' => Process::result('main'),
        default => Process::result(),
    });

    (new GitRepository)->ensureCloned('git@example.com:vault.git', 'main', $this->path);

    Process::assertNotRan(fn ($process) => in_array($process->command[1] ?? null, ['clone', 'fetch', 'checkout'], true));
    Process::assertNotRan(fn ($process) => ($process->command[1] ?? null) === 'remote' && ($process->command[2] ?? null) === 'set-url');
});

test('updates the remote and realigns the branch when the remote url changed', function () {
    File::ensureDirectoryExists($this->path.'/.git');

    Process::fake(fn ($process) => match (true) {
        ($process->command[1] ?? null) === 'remote' && ($process->command[2] ?? null) === 'get-url' => Process::result('git@example.com:old-vault.git'),
        ($process->command[1] ?? null) === 'rev-parse' => Process::result('main'),
        default => Process::result(),
    });

    (new GitRepository)->ensureCloned('git@example.com:new-vault.git', 'main', $this->path);

    Process::assertRan(fn ($process) => $process->command === ['git', 'remote', 'set-url', 'origin', 'git@example.com:new-vault.git']);
    Process::assertRan(fn ($process) => $process->command === ['git', 'fetch', 'origin']);
    Process::assertRan(fn ($process) => $process->command === ['git', 'checkout', '-B', 'main', '--track', 'origin/main']);
});

test('realigns the branch alone when only the branch changed', function () {
    File::ensureDirectoryExists($this->path.'/.git');

    Process::fake(fn ($process) => match (true) {
        ($process->command[1] ?? null) === 'remote' && ($process->command[2] ?? null) === 'get-url' => Process::result('git@example.com:vault.git'),
        ($process->command[1] ?? null) === 'rev-parse' => Process::result('old-branch'),
        default => Process::result(),
    });

    (new GitRepository)->ensureCloned('git@example.com:vault.git', 'new-branch', $this->path);

    Process::assertNotRan(fn ($process) => ($process->command[1] ?? null) === 'remote' && ($process->command[2] ?? null) === 'set-url');
    Process::assertRan(fn ($process) => $process->command === ['git', 'fetch', 'origin']);
    Process::assertRan(fn ($process) => $process->command === ['git', 'checkout', '-B', 'new-branch', '--track', 'origin/new-branch']);
});

test('pull reports the success message', function () {
    File::ensureDirectoryExists($this->path);
    Process::fake(fn () => Process::result('Already up to date.'));

    $result = (new GitRepository)->pull($this->path);

    expect($result->successful)->toBeTrue()
        ->and($result->message)->toBe('Already up to date.');
});

test('pull reports the failure message', function () {
    File::ensureDirectoryExists($this->path);
    Process::fake(fn () => Process::result(errorOutput: 'conflict', exitCode: 1));

    $result = (new GitRepository)->pull($this->path);

    expect($result->successful)->toBeFalse()
        ->and($result->message)->toBe('conflict');
});

test('lsRemote returns true on success', function () {
    Process::fake(fn () => Process::result('ref'));

    expect((new GitRepository)->lsRemote('git@example.com:vault.git'))->toBeTrue();
});

test('lsRemote returns false on failure', function () {
    Process::fake(fn () => Process::result(exitCode: 128));

    expect((new GitRepository)->lsRemote('git@example.com:vault.git'))->toBeFalse();
});

test('status is unknown when the vault has not been cloned yet', function () {
    expect((new GitRepository)->status($this->path))->toBe(GitSyncStatus::Unknown);
});

test('status is synced when clean and up to date', function () {
    File::ensureDirectoryExists($this->path.'/.git');

    Process::fake(fn ($process) => match ($process->command[1] ?? null) {
        'status' => Process::result(''),
        'fetch' => Process::result(''),
        'rev-list' => Process::result('0'),
        default => Process::result(),
    });

    expect((new GitRepository)->status($this->path))->toBe(GitSyncStatus::Synced);
});

test('status is behind when the remote has new commits', function () {
    File::ensureDirectoryExists($this->path.'/.git');

    Process::fake(fn ($process) => match ($process->command[1] ?? null) {
        'status' => Process::result(''),
        'fetch' => Process::result(''),
        'rev-list' => Process::result('3'),
        default => Process::result(),
    });

    expect((new GitRepository)->status($this->path))->toBe(GitSyncStatus::Behind);
});

test('status is conflict when the working tree has unmerged paths', function () {
    File::ensureDirectoryExists($this->path.'/.git');

    Process::fake(fn ($process) => match ($process->command[1] ?? null) {
        'status' => Process::result("UU notes/example.md\n"),
        default => Process::result(),
    });

    expect((new GitRepository)->status($this->path))->toBe(GitSyncStatus::Conflict);
});

test('commit stages and commits the given file', function () {
    File::ensureDirectoryExists($this->path);
    Process::fake();

    (new GitRepository)->commit($this->path, 'notes/example.md', 'tars: edit notes/example.md');

    Process::assertRan(fn ($process) => $process->command === ['git', 'add', 'notes/example.md']);
    Process::assertRan(fn ($process) => $process->command === ['git', 'commit', '-m', 'tars: edit notes/example.md']);
});

test('commitAll stages every change and commits', function () {
    File::ensureDirectoryExists($this->path);
    Process::fake();

    (new GitRepository)->commitAll($this->path, 'tars: curator move notes/example.md');

    Process::assertRan(fn ($process) => $process->command === ['git', 'add', '-A']);
    Process::assertRan(fn ($process) => $process->command === ['git', 'commit', '-m', 'tars: curator move notes/example.md']);
});
