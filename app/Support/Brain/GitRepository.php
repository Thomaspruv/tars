<?php

namespace App\Support\Brain;

use App\Enums\GitSyncStatus;
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

    public function lsRemote(string $remote): bool
    {
        return Process::run(['git', 'ls-remote', $remote])->successful();
    }

    public function status(string $path): GitSyncStatus
    {
        if (! File::isDirectory($path.'/.git')) {
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
        Process::path($path)->run(['git', 'add', $relativePath])->throw();
        Process::path($path)->run(['git', 'commit', '-m', $message])->throw();
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
