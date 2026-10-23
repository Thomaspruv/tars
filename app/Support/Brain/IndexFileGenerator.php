<?php

namespace App\Support\Brain;

use App\Models\BrainDocument;
use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\Schema\FrontmatterSchema;
use App\Support\Brain\Schema\NoteType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Writes one `_index.md` per structuring folder — the summaries an agent reads
 * to know what a folder holds. They carry a « généré par TARS » banner and are
 * regenerated from the index at every reindexation.
 *
 * What it deliberately does *not* write any more: the vault's root `README.md`.
 * The vault is a shared brain — Thomas in Obsidian, Hermes, TARS and whatever
 * comes next — so its front door belongs to the vault, not to one of its
 * writers. TARS reposing its own README there overwrote Hermes's every fifteen
 * minutes (`brain:index` is scheduled), committed the difference, and that
 * local commit is exactly what `pull --ff-only` then refused for three months.
 * `get_memory_map` reads whatever README and AGENTS.md the vault has instead.
 */
class IndexFileGenerator
{
    /**
     * Folders that get an `_index.md`, in reading order.
     *
     * Only folders the vault actually holds notes in: inventing the others
     * used to be how TARS's target tree « appeared » in Obsidian, but in a
     * shared vault it plants five empty folders next to the ones Thomas and
     * Hermes already use — two taxonomies in one repo, which is precisely the
     * mess to avoid.
     */
    public const array INDEXED_FOLDERS = ['Projets', 'Personnes', 'Organisations', 'Veille', 'Skills'];

    /**
     * Folders whose sub-folders each deserve their own index — a veille
     * subject, a skill category.
     */
    public const array NESTED_FOLDERS = ['Veille', 'Skills'];

    private const string BANNER = '> **Généré par TARS** — ne pas éditer. Ce fichier est réécrit à chaque réindexation du cerveau.';

    public function __construct(
        private readonly BrainSettings $settings,
        private readonly VaultWriter $writer,
        private readonly VaultParser $parser,
        private readonly FrontmatterBuilder $frontmatter,
    ) {}

    /**
     * The files that need rewriting — and only those, so that a reindexation
     * which changes nothing produces an empty git diff (A-P0.2).
     *
     * @return list<VaultOperation>
     */
    public function operations(): array
    {
        $operations = [];

        foreach ($this->targets() as $relativePath => [$title, $body]) {
            if ($this->unchanged($relativePath, $body)) {
                continue;
            }

            $operations[] = VaultOperation::write(
                $relativePath,
                $this->frontmatter->build(NoteType::Note, $title, now(), 'agent-tars'),
                $body,
            );
        }

        return $operations;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function targets(): array
    {
        $documents = BrainDocument::query()
            ->orderBy('path')
            ->get(['path', 'title', 'note_type', 'status', 'updated_on', 'mtime']);

        $targets = [];

        foreach (self::INDEXED_FOLDERS as $folder) {
            $inFolder = $documents->filter(fn (BrainDocument $document): bool => str_starts_with($document->path, "{$folder}/"));

            // Pas de note, pas d'index : on ne crée pas le dossier pour y
            // poser un sommaire vide.
            if ($inFolder->isEmpty()) {
                continue;
            }

            $targets["{$folder}/_index.md"] = ["Index — {$folder}", $this->table($folder, $inFolder)];

            if (! in_array($folder, self::NESTED_FOLDERS, true)) {
                continue;
            }

            foreach ($this->subFolders($folder, $inFolder) as $subFolder) {
                $inSubFolder = $inFolder->filter(fn (BrainDocument $document): bool => str_starts_with($document->path, "{$folder}/{$subFolder}/"));

                $targets["{$folder}/{$subFolder}/_index.md"] = ["Index — {$folder} / {$subFolder}", $this->table("{$folder}/{$subFolder}", $inSubFolder)];
            }
        }

        return $targets;
    }

    /**
     * @param  Collection<int, BrainDocument>  $documents
     * @return list<string>
     */
    private function subFolders(string $folder, Collection $documents): array
    {
        return array_values($documents
            ->map(fn (BrainDocument $document): string => explode('/', substr($document->path, strlen($folder) + 1))[0])
            ->filter(fn (string $segment): bool => $segment !== '' && ! str_ends_with($segment, '.md'))
            ->unique()
            ->sort()
            ->all());
    }

    /**
     * @param  Collection<int, BrainDocument>  $documents
     */
    private function table(string $folder, Collection $documents): string
    {
        $rows = $documents
            ->reject(fn (BrainDocument $document): bool => basename($document->path) === '_index.md')
            ->sortBy([
                fn (BrainDocument $a, BrainDocument $b): int => $this->freshness($b) <=> $this->freshness($a),
                fn (BrainDocument $a, BrainDocument $b): int => $a->path <=> $b->path,
            ]);

        if ($rows->isEmpty()) {
            return self::BANNER."\n\n# {$folder}\n\nAucune note pour l'instant.\n";
        }

        $lines = $rows->map(function (BrainDocument $document) use ($folder): string {
            $relative = substr($document->path, strlen($folder) + 1);
            $type = $document->noteType()?->label() ?? '—';
            $updated = $this->freshness($document) ?: '—';

            return "| {$document->title} | {$type} | ".($document->status ?? '—')." | {$updated} | [{$relative}]({$relative}) |";
        })->implode("\n");

        return self::BANNER."\n\n# {$folder}\n\n| Titre | Type | Statut | Mise à jour | Fichier |\n|---|---|---|---|---|\n{$lines}\n";
    }

    private function freshness(BrainDocument $document): string
    {
        return $document->updated_on?->toDateString() ?? $document->mtime?->toDateString() ?? '';
    }

    /**
     * Only the body is compared, so that a reindexation which changes nothing
     * leaves a null diff — the frontmatter carries today's date and would
     * otherwise move every day. Its *version* is still checked: a generated
     * file whose frontmatter drifted (hand-stripped, or written under an older
     * contract) is rewritten.
     */
    private function unchanged(string $relativePath, string $body): bool
    {
        $absolutePath = rtrim($this->settings->localPath(), '/')."/{$relativePath}";

        if (! File::exists($absolutePath)) {
            return false;
        }

        $parsed = $this->parser->parse(File::get($absolutePath), '');

        if ($parsed->schema->schemaVersion !== FrontmatterSchema::CURRENT_VERSION) {
            return false;
        }

        return trim($parsed->content) === trim($body);
    }

    /**
     * Writes everything that changed in a single `tars: index` commit, and
     * commits nothing at all when nothing changed.
     */
    public function write(): WriteResult
    {
        return $this->writer->apply($this->operations(), 'index');
    }
}
