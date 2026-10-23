<?php

namespace App\Support\Brain;

use App\Models\BrainDocument;
use App\Support\Brain\Schema\FrontmatterSchema;
use Illuminate\Support\Facades\File;

class VaultIndexer
{
    public function __construct(
        private readonly BrainSettings $settings,
        private readonly VaultParser $parser,
        private readonly AnchorResolver $anchors,
    ) {}

    public function indexAll(bool $fresh = false): IndexSummary
    {
        $vaultPath = rtrim($this->settings->localPath(), '/');

        if ($fresh) {
            BrainDocument::query()->delete();
        }

        if (! File::isDirectory($vaultPath)) {
            return new IndexSummary(created: 0, updated: 0, unchanged: 0, deleted: 0);
        }

        // Hidden files are included so that `.hermes/` — Hermes's own memory
        // territory — is searchable; every other dot-folder (.git, .obsidian,
        // .github) stays out of the index.
        $files = collect(File::allFiles($vaultPath, hidden: true))
            ->filter(fn ($file): bool => $file->getExtension() === 'md')
            ->reject(fn ($file): bool => $this->isHiddenFolder($file->getRelativePathname()));

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $seenPaths = [];

        foreach ($files as $file) {
            $relativePath = $file->getRelativePathname();
            $seenPaths[] = $relativePath;

            match ($this->indexFileEntry($file->getPathname(), $relativePath)) {
                'created' => $created++,
                'updated' => $updated++,
                'unchanged' => $unchanged++,
            };
        }

        $deleted = BrainDocument::whereNotIn('path', $seenPaths)->delete();

        $this->settings->markIndexed();

        return new IndexSummary($created, $updated, $unchanged, $deleted);
    }

    public function indexFile(string $absolutePath): BrainDocument
    {
        $vaultPath = rtrim($this->settings->localPath(), '/');
        $relativePath = ltrim(str_replace($vaultPath, '', $absolutePath), '/');

        $this->indexFileEntry($absolutePath, $relativePath);

        return BrainDocument::where('path', $relativePath)->firstOrFail();
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    private function indexFileEntry(string $absolutePath, string $relativePath): string
    {
        $raw = File::get($absolutePath);
        $hash = hash('sha256', $raw);

        $existing = BrainDocument::where('path', $relativePath)->first();

        // Le texte de recherche manquant fait relire une note dont le hash n'a
        // pas bougé : c'est ce qui remplit la colonne sur un vault déjà indexé,
        // sans demander un `brain:index --fresh` à la main.
        if ($existing && $existing->hash === $hash && $existing->search_text !== null) {
            return 'unchanged';
        }

        $parsed = $this->parser->parse($raw, pathinfo($relativePath, PATHINFO_FILENAME));
        $updatedOn = $parsed->schema->normalized['updated'] ?? null;

        $document = BrainDocument::updateOrCreate(
            ['path' => $relativePath],
            [
                'title' => $parsed->title,
                'frontmatter' => $parsed->frontmatter,
                'schema_version' => $parsed->schema->schemaVersion,
                'is_conformant' => $parsed->schema->isConformant,
                'note_type' => $parsed->schema->noteType?->value,
                'status' => $parsed->schema->status,
                'updated_on' => is_string($updatedOn) ? $updatedOn : null,
                'content' => $parsed->content,
                'search_text' => NoteSearch::haystack($parsed->title, $parsed->content),
                'hash' => $parsed->hash,
                'mtime' => File::lastModified($absolutePath),
            ]
        );

        // The incentive to honour the contract (§A.5): a non conformant note is
        // indexed full-text only — its `entity`/`goal` anchors are not resolved,
        // so it never shows up on an entity or goal page. Profil/ and Hermes's
        // own territory are exempt: nobody holds them to the contract.
        $resolved = $parsed->schema->isConformant || FrontmatterSchema::isExempt($relativePath)
            ? $this->anchors->resolve($parsed)
            : ['entities' => [], 'goals' => []];

        $document->entities()->sync(collect($resolved['entities'])->pluck('id'));
        $document->goals()->sync(collect($resolved['goals'])->pluck('id'));

        return $existing ? 'updated' : 'created';
    }

    private function isHiddenFolder(string $relativePathname): bool
    {
        foreach (explode('/', dirname($relativePathname)) as $segment) {
            if (str_starts_with($segment, '.') && $segment !== '.hermes' && $segment !== '.') {
                return true;
            }
        }

        return false;
    }
}
