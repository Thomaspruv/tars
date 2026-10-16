<?php

namespace App\Support\Brain;

use App\Jobs\PushVaultJob;
use App\Models\BrainDocument;
use App\Support\Brain\Schema\FrontmatterSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * The single machine write path into the vault — SPEC-TARS-OS-v1 §A.8.
 *
 * Nothing else in `app/` writes a vault file: Hermes's MCP tools, the
 * curator, the journal and the bilan all come through here, which is what
 * makes the three guarantees hold in one place — pull-rebase before writing,
 * a conformant frontmatter, a prefixed commit, and a pushed repository.
 */
class VaultWriter
{
    /**
     * A readable, stable key order — the diff of a regenerated file stays
     * about its content, never about the order of its frontmatter.
     */
    private const array KEY_ORDER = [
        'schema', 'type', 'title', 'date', 'updated', 'status', 'project',
        'entity', 'goal', 'tags', 'source', 'related', 'archived', 'conflict',
    ];

    public function __construct(
        private readonly BrainSettings $settings,
        private readonly GitRepository $git,
        private readonly VaultIndexer $indexer,
        private readonly FrontmatterSchema $schema,
    ) {}

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function write(string $relativePath, array $frontmatter, string $body, string $commitMessage): WriteResult
    {
        return $this->apply([VaultOperation::write($relativePath, $frontmatter, $body)], $commitMessage);
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function move(string $from, string $to, array $frontmatter, string $body, string $commitMessage): WriteResult
    {
        return $this->apply([VaultOperation::move($from, $to, $frontmatter, $body)], $commitMessage);
    }

    /**
     * Thomas's own save from `/cerveau` — same git sequence, but his text is
     * written verbatim and not held to the contract.
     */
    public function writeRaw(string $relativePath, string $contents, string $commitMessage): WriteResult
    {
        return $this->apply([VaultOperation::raw($relativePath, $contents)], $commitMessage);
    }

    /**
     * Writes every operation in one commit, or writes nothing at all.
     *
     * @param  list<VaultOperation>  $operations
     */
    public function apply(array $operations, string $commitMessage): WriteResult
    {
        if (! $this->settings->isConfigured()) {
            return WriteResult::unconfigured();
        }

        if ($operations === []) {
            return WriteResult::written([]);
        }

        $errors = $this->validate($operations);

        if ($errors !== []) {
            return WriteResult::invalid($errors);
        }

        $vaultPath = rtrim($this->settings->localPath(), '/');
        $isRepository = $this->git->isRepository($vaultPath);

        if ($isRepository) {
            $pull = $this->git->pullRebase($vaultPath);

            if (! $pull->successful) {
                return $this->divertToSas($operations, $pull->message);
            }
        }

        foreach ($operations as $operation) {
            $this->put($vaultPath, $operation);
        }

        if ($isRepository) {
            $this->commit($vaultPath, $operations, $commitMessage);
        }

        foreach ($operations as $operation) {
            $this->indexer->indexFile("{$vaultPath}/{$operation->relativePath}");
        }

        if ($isRepository) {
            PushVaultJob::dispatch();
        }

        $this->settings->clearConflict();

        return WriteResult::written(array_map(fn (VaultOperation $operation): string => $operation->relativePath, $operations));
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function render(array $frontmatter, string $body): string
    {
        return "---\n".Yaml::dump($this->ordered($frontmatter), 4)."---\n\n".ltrim($body);
    }

    /**
     * An agent has no right to produce a non conformant note: the contract is
     * enforced on the way in, not reported after the fact.
     *
     * @param  list<VaultOperation>  $operations
     * @return list<string>
     */
    private function validate(array $operations): array
    {
        $errors = [];

        foreach ($operations as $operation) {
            if ($operation->isRaw()) {
                continue;
            }

            $result = $this->schema->validate($operation->frontmatter);

            if (! $result->isConformant) {
                $errors[] = "{$operation->relativePath} : ".$result->errorSentence();
            }
        }

        return $errors;
    }

    private function put(string $vaultPath, VaultOperation $operation): void
    {
        $absolutePath = "{$vaultPath}/{$operation->relativePath}";

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $operation->rawContents ?? $this->render($operation->frontmatter, $operation->body));

        if (! $operation->isMove()) {
            return;
        }

        File::delete("{$vaultPath}/{$operation->movedFrom}");

        $source = BrainDocument::where('path', $operation->movedFrom)->first();

        if ($source === null) {
            return;
        }

        // The index row follows the file instead of being recreated: a moved
        // note keeps its id, so its anchors and the suggestion that moved it
        // survive the move.
        if (BrainDocument::where('path', $operation->relativePath)->whereKeyNot($source->getKey())->exists()) {
            $source->delete();

            return;
        }

        $source->update(['path' => $operation->relativePath]);
    }

    /**
     * @param  list<VaultOperation>  $operations
     */
    private function commit(string $vaultPath, array $operations, string $commitMessage): void
    {
        $paths = [];

        foreach ($operations as $operation) {
            $paths[] = $operation->relativePath;

            if ($operation->isMove()) {
                $paths[] = (string) $operation->movedFrom;
            }
        }

        $this->git->commitPaths($vaultPath, $paths, $this->prefix($operations[0]->frontmatter).$commitMessage);
    }

    /**
     * The commit prefix is deduced from `source` and never passed in: what
     * Hermes writes is `hermes:`, everything TARS writes is `tars:`.
     *
     * @param  array<string, mixed>  $frontmatter
     */
    private function prefix(array $frontmatter): string
    {
        return ($frontmatter['source'] ?? null) === 'hermes' ? 'hermes: ' : 'tars: ';
    }

    /**
     * A divergent vault is never overwritten: the content is parked in the sas
     * as an `a-traiter` note and Thomas is told in Réglages → Cerveau.
     *
     * @param  list<VaultOperation>  $operations
     */
    private function divertToSas(array $operations, string $gitMessage): WriteResult
    {
        $vaultPath = rtrim($this->settings->localPath(), '/');
        $first = $operations[0];
        $title = (string) ($first->frontmatter['title'] ?? basename($first->relativePath, '.md'));
        $slug = Str::slug($title) ?: 'note';
        $sasPath = 'TARS/'.now()->toDateString()."-conflit-{$slug}.md";

        $body = collect($operations)->map(fn (VaultOperation $operation): string => "## Destination prévue : `{$operation->relativePath}`\n\n".trim($operation->body))->implode("\n\n");

        $frontmatter = [
            'schema' => FrontmatterSchema::CURRENT_VERSION,
            'type' => 'a-traiter',
            'title' => "Conflit git — {$title}",
            'date' => now()->toDateString(),
            'updated' => now()->toDateString(),
            'status' => 'active',
            'source' => $first->frontmatter['source'] ?? 'thomas',
            'conflict' => true,
        ];

        $absolutePath = "{$vaultPath}/{$sasPath}";
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $this->render($frontmatter, $body));

        // Committed but deliberately not pushed: the repository is diverged, a
        // push would be rejected. The next successful write carries it along.
        $this->git->commit($vaultPath, $sasPath, $this->prefix($frontmatter)."conflit {$sasPath}");
        $this->indexer->indexFile($absolutePath);

        $this->settings->recordConflict("Conflit git — le contenu a été gardé dans {$sasPath}. Message de git : {$gitMessage}");

        return WriteResult::conflict($sasPath);
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     * @return array<string, mixed>
     */
    private function ordered(array $frontmatter): array
    {
        $ordered = [];

        foreach (self::KEY_ORDER as $key) {
            if (array_key_exists($key, $frontmatter)) {
                $ordered[$key] = $frontmatter[$key];
            }
        }

        foreach ($frontmatter as $key => $value) {
            $ordered[$key] ??= $value;
        }

        return $ordered;
    }
}
