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

    /**
     * Un pull qui ne réécrit jamais rien — donc un pull qui ne rattrape jamais
     * un remote dès qu'un commit local existe. N'en faire la synchro
     * périodique de rien : c'est `pullRebase()` qu'il faut (voir
     * SyncBrainCommand, qui a payé trois mois de vault figé pour l'apprendre).
     */
    public function pull(string $path): GitPullResult
    {
        $result = Process::path($path)->run(['git', 'pull', '--ff-only']);

        return new GitPullResult(
            successful: $result->successful(),
            message: trim($result->successful() ? $result->output() : $result->errorOutput()),
        );
    }

    /**
     * Not being able to reach the remote is not a divergence: nothing was
     * rewritten, so the write may land locally and the push job carries it
     * later. Anything else that makes the pull fail does block the write.
     */
    /**
     * A pull git refuses to even start, because Thomas has uncommitted changes
     * in the vault right now. Writing is still safe — nothing was rewritten —
     * but the vault is behind, so it is reported as a warning rather than
     * swallowed or treated as a divergence.
     */
    private const array LOCAL_CHANGES_PATTERNS = [
        'cannot pull with rebase',
        'you have unstaged changes',
        'local changes would be overwritten',
        'your index contains uncommitted changes',
        'please commit or stash',
    ];

    private const array UNREACHABLE_REMOTE_PATTERNS = [
        'there is no tracking information',
        'could not read from remote repository',
        'could not resolve host',
        'unable to access',
        'does not appear to be a git repository',
        'no such remote',
        'network is unreachable',
        'connection timed out',
        'operation timed out',
    ];

    /**
     * The pull that runs before every machine write (§A.8). A genuine
     * divergence leaves a rebase in progress: it is aborted so the working
     * tree is left exactly as it was, and reported as unsuccessful so the
     * caller writes nothing.
     *
     * A pull that refuses to start, or a remote out of reach, is not a
     * divergence: nothing was rewritten, so the write lands locally and the
     * reason comes back as a `warning` for Réglages → Cerveau. Anything else
     * blocks the write — the §A.8 guarantee holds or is reported.
     */
    public function pullRebase(string $path): GitPullResult
    {
        $result = Process::path($path)->run(['git', 'pull', '--rebase']);

        if ($result->successful()) {
            return new GitPullResult(successful: true, message: trim($result->output()));
        }

        $message = trim($result->errorOutput() ?: $result->output());

        if ($this->isRebasing($path)) {
            Process::path($path)->run(['git', 'rebase', '--abort']);

            return new GitPullResult(successful: false, message: $message);
        }

        if ($this->matches($message, self::LOCAL_CHANGES_PATTERNS)) {
            return new GitPullResult(
                successful: true,
                message: $message,
                warning: "Le vault n'a pas pu être mis à jour avant l'écriture : des modifications ne sont pas encore committées dans le dépôt local. La synchro reprendra d'elle-même ensuite.",
            );
        }

        if ($this->matches($message, self::UNREACHABLE_REMOTE_PATTERNS)) {
            return new GitPullResult(
                successful: true,
                message: $message,
                warning: "Le dépôt distant est injoignable : l'écriture est locale, le push suivra.",
            );
        }

        return new GitPullResult(successful: false, message: $message);
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(string $message, array $patterns): bool
    {
        $normalized = mb_strtolower($message);

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
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
        $stageable = $this->stageable($path, $relativePaths);

        if ($stageable === []) {
            return;
        }

        Process::path($path)->run(['git', 'add', '--', ...$stageable])->throw();
        Process::path($path)->run(['git', 'commit', '-m', $message, '--', ...$stageable])->throw();
    }

    /**
     * Paths git can actually stage: on disk, or tracked and therefore
     * stageable as a deletion. A note created in Obsidian and archived before
     * the git plugin committed it has a source path git never knew — passing it
     * to `git add` would abort the whole commit.
     *
     * @param  list<string>  $relativePaths
     * @return list<string>
     */
    private function stageable(string $path, array $relativePaths): array
    {
        $tracked = Process::path($path)
            ->run(['git', '-c', 'core.quotePath=false', 'ls-files', '--', ...$relativePaths])
            ->output();

        $tracked = array_filter(explode("\n", str_replace("\r", '', $tracked)));

        return array_values(array_filter(
            $relativePaths,
            fn (string $relativePath): bool => File::exists("{$path}/{$relativePath}") || in_array($relativePath, $tracked, true),
        ));
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
