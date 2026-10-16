<?php

namespace App\Support\Brain;

use App\Support\Brain\Schema\FrontmatterBuilder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Archiving is the only way a note ever leaves its folder for good: nothing is
 * deleted, anywhere, by any path of code (§A.3). The origin folder is kept
 * under `Archives/` so two notes that share a filename cannot overwrite each
 * other on their way out.
 */
class NoteArchiver
{
    public function __construct(
        private readonly BrainSettings $settings,
        private readonly VaultWriter $writer,
        private readonly VaultParser $parser,
        private readonly FrontmatterBuilder $frontmatter,
    ) {}

    public function archivePath(string $relativePath): string
    {
        $folder = dirname($relativePath);

        return 'Archives/'.($folder === '.' ? '' : "{$folder}/").basename($relativePath);
    }

    /**
     * @param  array<string, mixed>  $extraFrontmatter
     *
     * @throws RuntimeException when the note has already left the vault.
     */
    public function operationFor(string $relativePath, string $source, array $extraFrontmatter = []): VaultOperation
    {
        $absolutePath = rtrim($this->settings->localPath(), '/')."/{$relativePath}";

        if (! File::exists($absolutePath)) {
            throw new RuntimeException("La note « {$relativePath} » n'est plus dans le vault.");
        }

        $parsed = $this->parser->parse(File::get($absolutePath), basename($relativePath, '.md'));

        $frontmatter = $this->frontmatter->upgrade(
            frontmatter: $parsed->frontmatter,
            relativePath: $relativePath,
            fallbackTitle: $parsed->title,
            source: $source,
            patch: [...$extraFrontmatter, 'archived' => now()->toDateString()],
        );

        return VaultOperation::move($relativePath, $this->archivePath($relativePath), $frontmatter, $parsed->content);
    }

    /**
     * @param  array<string, mixed>  $extraFrontmatter
     */
    public function archive(string $relativePath, string $source, string $commitMessage, array $extraFrontmatter = []): WriteResult
    {
        return $this->writer->apply([$this->operationFor($relativePath, $source, $extraFrontmatter)], $commitMessage);
    }
}
