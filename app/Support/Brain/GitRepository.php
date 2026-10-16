<?php

namespace App\Support\Brain;

use App\Enums\GitSyncStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class GitRepository
{
    private const array CONFLICT_STATUS_CODES = ['UU', 'AA', 'DD', 'AU', 'UA', 'DU', 'UD'];

    public function ensureCloned(string $remote, string $branch, string $path): void
    {
        if (! File::isDirectory($path.'/.git')) {
            File::ensureDirectoryExists(dirname($path));

            Process::run(['git', 'clone', '--branch', $branch, '--single-branch', $remote, $path])->throw();

            return;
        }

        $this->realign($remote, $branch, $path);
    }

    private function realign(string $remote, string $branch, string $path): void
    {
        $currentRemote = trim(Process::path($path)->run(['git', 'remote', 'get-url', 'origin'])->output());
        $currentBranch = trim(Process::path($path)->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])->output());

        if ($currentRemote === $remote && $currentBranch === $branch) {
            return;
        }

        if ($currentRemote !== $remote) {
            Process::path($path)->run(['git', 'remote', 'set-url', 'origin', $remote])->throw();
        }

        Process::path($path)->run(['git', 'fetch', 'origin'])->throw();
        Process::path($path)->run(['git', 'checkout', '-B', $branch, '--track', "origin/{$branch}"])->throw();
    }

    public function pull(string $path): GitPullResult
    {
        $result = Process::path($path)->run(['git', 'pull', '--ff-only']);

        return new GitPullResult(
            successful: $result->successful(),
            message: trim($result->successful() ? $result->output() : $result->errorOutput()),
        );
    }

    /**
     * The pull that runs before every machine write (§A.8). A genuine
     * divergence leaves a rebase in progress: it is aborted so the working
     * tree is left exactly as it was, and reported as unsuccessful so the
     * caller writes nothing.
     *
     * Being unable to reach the remote at all (offline, no upstream) is not a
     * conflict: nothing was rewritten, so the write may proceed locally and
     * the push job will carry it later.
     */
    public function pullRebase(string $path): GitPullResult
    {
        $result = Process::path($path)->run(['git', 'pull', '--rebase']);

        if ($result->successful()) {
            return new GitPullResult(successful: true, message: trim($result->output()));
        }

        $message = trim($result->errorOutput() ?: $result->output());

        if (! $this->isRebasing($path)) {
            return new GitPullResult(successful: true, message: $message);
        }

        Process::path($path)->run(['git', 'rebase', '--abort']);

        return new GitPullResult(successful: false, message: $message);
    }

    public function push(string $path): void
    {
        Process::path($path)->run(['git', 'push'])->throw();
    }

    /**
     * When the remote last received what we have — the upstream ref's own
     * commit date, which is the only honest answer.
     */
    public function lastPushAt(string $path): ?CarbonInterface
    {
        if (! $this->isRepository($path)) {
            return null;
        }

        $result = Process::path($path)->run(['git', 'log', '-1', '--format=%ct', '@{u}']);
        $timestamp = (int) trim($result->output());

        return $result->successful() && $timestamp > 0 ? Carbon::createFromTimestampUTC($timestamp) : null;
    }

    public function isRepository(string $path): bool
    {
        return File::isDirectory($path.'/.git');
    }

    private function isRebasing(string $path): bool
    {
        return File::isDirectory($path.'/.git/rebase-merge') || File::isDirectory($path.'/.git/rebase-apply');
    }

    public function lsRemote(string $remote): bool
    {
        return Process::run(['git', 'ls-remote', $remote])->successful();
    }

    public function status(string $path): GitSyncStatus
    {
        if (! $this->isRepository($path)) {
            return GitSyncStatus::Unknown;
        }

        $porcelain = Process::path($path)->run(['git', 'status', '--porcelain']);

        if ($this->hasConflictMarkers($porcelain->output())) {
            return GitSyncStatus::Conflict;
        }

        Process::path($path)->run(['git', 'fetch']);

        $behind = Process::path($path)->run(['git', 'rev-list', '--count', 'HEAD..@{u}']);

        if ($behind->successful() && (int) trim($behind->output()) > 0) {
            return GitSyncStatus::Behind;
        }

        return GitSyncStatus::Synced;
    }

    public function commit(string $path, string $relativePath, string $message): void
    {
        $this->commitPaths($path, [$relativePath], $message);
    }

    /**
     * Commits exactly the given paths and nothing else — a move's source and
     * destination, a batch of regenerated indexes.
     *
     * The pathspec matters: Thomas edits the same working tree in Obsidian, so
     * a `git add -A` here would sweep his in-progress changes (or a pending
     * deletion) into TARS's commit.
     *
     * @param  list<string>  $relativePaths
     */
    public function commitPaths(string $path, array $relativePaths, string $message): void
    {
        Process::path($path)->run(['git', 'add', '--', ...$relativePaths])->throw();
        Process::path($path)->run(['git', 'commit', '-m', $message, '--', ...$relativePaths])->throw();
    }

    private function hasConflictMarkers(string $porcelainOutput): bool
    {
        foreach (explode("\n", trim($porcelainOutput)) as $line) {
            if ($line === '') {
                continue;
            }

            if (in_array(substr($line, 0, 2), self::CONFLICT_STATUS_CODES, true)) {
                return true;
            }
        }

        return false;
    }
}
