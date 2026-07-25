<?php

namespace App\Support\Brain;

use App\Models\BrainDocument;
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

        $files = collect(File::allFiles($vaultPath))
            ->filter(fn ($file): bool => $file->getExtension() === 'md')
            ->reject(fn ($file): bool => str_contains($file->getRelativePath(), '.git') || str_contains($file->getRelativePath(), '.obsidian'));

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

        if ($existing && $existing->hash === $hash) {
            return 'unchanged';
        }

        $parsed = $this->parser->parse($raw, pathinfo($relativePath, PATHINFO_FILENAME));

        $document = BrainDocument::updateOrCreate(
            ['path' => $relativePath],
            [
                'title' => $parsed->title,
                'frontmatter' => $parsed->frontmatter,
                'content' => $parsed->content,
                'hash' => $parsed->hash,
                'mtime' => File::lastModified($absolutePath),
            ]
        );

        $resolved = $this->anchors->resolve($parsed);

        $document->entities()->sync(collect($resolved['entities'])->pluck('id'));
        $document->goals()->sync(collect($resolved['goals'])->pluck('id'));

        return $existing ? 'updated' : 'created';
    }
}
