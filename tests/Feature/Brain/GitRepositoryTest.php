<?php

use App\Enums\GitSyncStatus;
use App\Support\Brain\GitRepository;
use Illuminate\Process\Exceptions\ProcessFailedException;
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
    File::ensureDirectoryExists($this->path.'/notes');
    File::put($this->path.'/notes/example.md', '# Exemple');
    Process::fake();

    (new GitRepository)->commit($this->path, 'notes/example.md', 'tars: edit notes/example.md');

    Process::assertRan(fn ($process) => $process->command === ['git', 'add', '--', 'notes/example.md']);
    Process::assertRan(fn ($process) => $process->command === ['git', 'commit', '-m', 'tars: edit notes/example.md', '--', 'notes/example.md']);
});

test('commitPaths commits exactly the given paths and nothing else', function () {
    File::ensureDirectoryExists($this->path.'/Notes');
    File::ensureDirectoryExists($this->path.'/Archives');
    File::put($this->path.'/Notes/a.md', '# A');
    File::put($this->path.'/Archives/a.md', '# A');
    Process::fake();

    (new GitRepository)->commitPaths($this->path, ['Notes/a.md', 'Archives/a.md'], 'tars: curator move Notes/a.md');

    Process::assertRan(fn ($process) => $process->command === ['git', 'add', '--', 'Notes/a.md', 'Archives/a.md']);
    Process::assertRan(fn ($process) => $process->command === ['git', 'commit', '-m', 'tars: curator move Notes/a.md', '--', 'Notes/a.md', 'Archives/a.md']);
    Process::assertNotRan(fn ($process) => in_array('-A', $process->command, true));
});

test('pullRebase reports success when the rebase goes through', function () {
    File::ensureDirectoryExists($this->path);
    Process::fake(fn () => Process::result('Successfully rebased.'));

    $result = (new GitRepository)->pullRebase($this->path);

    expect($result->successful)->toBeTrue();
});

test('pullRebase aborts and refuses the write when a rebase is left in progress', function () {
    File::ensureDirectoryExists($this->path.'/.git/rebase-merge');
    Process::fake(fn () => Process::result(errorOutput: 'CONFLICT (content): Merge conflict', exitCode: 1));

    $result = (new GitRepository)->pullRebase($this->path);

    expect($result->successful)->toBeFalse()
        ->and($result->message)->toContain('CONFLICT');

    Process::assertRan(fn ($process) => $process->command === ['git', 'rebase', '--abort']);
});

test('pullRebase treats an unreachable remote as no obstacle to a local write', function () {
    File::ensureDirectoryExists($this->path.'/.git');
    Process::fake(fn () => Process::result(errorOutput: 'could not read from remote repository', exitCode: 1));

    $result = (new GitRepository)->pullRebase($this->path);

    expect($result->successful)->toBeTrue();
    Process::assertNotRan(fn ($process) => ($process->command[1] ?? null) === 'rebase');
});

test('push throws when git refuses it', function () {
    File::ensureDirectoryExists($this->path);
    Process::fake(fn () => Process::result(errorOutput: 'rejected', exitCode: 1));

    expect(fn () => (new GitRepository)->push($this->path))->toThrow(ProcessFailedException::class);
});

test('lastPushAt reads the upstream commit date, and is null outside a repository', function () {
    expect((new GitRepository)->lastPushAt($this->path))->toBeNull();

    File::ensureDirectoryExists($this->path.'/.git');
    Process::fake(fn () => Process::result('1792108800'));

    expect((new GitRepository)->lastPushAt($this->path)?->toDateString())->toBe('2026-10-16');
});

test('isRepository tells a clone from a plain folder', function () {
    File::ensureDirectoryExists($this->path);
    expect((new GitRepository)->isRepository($this->path))->toBeFalse();

    File::ensureDirectoryExists($this->path.'/.git');
    expect((new GitRepository)->isRepository($this->path))->toBeTrue();
});

test('pullRebase lets a local write through only when the remote is out of reach', function (string $message) {
    File::ensureDirectoryExists($this->path.'/.git');
    Process::fake(fn () => Process::result(errorOutput: $message, exitCode: 1));

    expect((new GitRepository)->pullRebase($this->path)->successful)->toBeTrue();
})->with([
    'pas de remote suivi' => ['There is no tracking information for the current branch.'],
    'hôte injoignable' => ['ssh: Could not resolve host github.com'],
    'dépôt inaccessible' => ['fatal: unable to access https://github.com/x/y.git'],
]);

test('pullRebase reports a dirty working tree as a warning, not a divergence', function () {
    File::ensureDirectoryExists($this->path.'/.git');
    Process::fake(fn () => Process::result(errorOutput: 'error: cannot pull with rebase: You have unstaged changes.', exitCode: 1));

    $result = (new GitRepository)->pullRebase($this->path);

    // Nothing was rewritten, so the write may land — but Thomas is told.
    expect($result->successful)->toBeTrue()
        ->and($result->warning)->toContain('pas encore committées');
});

test('pullRebase blocks the write on anything it cannot explain', function () {
    File::ensureDirectoryExists($this->path.'/.git');
    Process::fake(fn () => Process::result(errorOutput: 'fatal: something nobody expected', exitCode: 1));

    expect((new GitRepository)->pullRebase($this->path)->successful)->toBeFalse();
});

test('commitPaths skips a path git has never known', function () {
    File::ensureDirectoryExists($this->path);
    Process::fake(fn ($process) => ($process->command[3] ?? null) === 'ls-files' ? Process::result('') : Process::result());

    // Neither on disk nor tracked: passing it to `git add` would abort the commit.
    (new GitRepository)->commitPaths($this->path, ['Notes/jamais-vue.md'], 'tars: archive');

    Process::assertNotRan(fn ($process) => ($process->command[1] ?? null) === 'add');
    Process::assertNotRan(fn ($process) => ($process->command[1] ?? null) === 'commit');
});

test('commitPaths stages a tracked path that has just been deleted', function () {
    File::ensureDirectoryExists($this->path);
    Process::fake(fn ($process) => ($process->command[3] ?? null) === 'ls-files' ? Process::result("Notes/deplacee.md\n") : Process::result());

    (new GitRepository)->commitPaths($this->path, ['Notes/deplacee.md'], 'tars: curator move');

    Process::assertRan(fn ($process) => $process->command === ['git', 'add', '--', 'Notes/deplacee.md']);
});
