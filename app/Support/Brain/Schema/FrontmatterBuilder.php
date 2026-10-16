<?php

namespace App\Support\Brain\Schema;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Builds frontmatter that satisfies the v3 contract — for a new machine-written
 * note, or by completing a legacy one in place (the curator's `complete`).
 */
class FrontmatterBuilder
{
    public function __construct(private readonly FrontmatterSchema $schema = new FrontmatterSchema) {}

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function build(NoteType $type, string $title, ?CarbonInterface $date, string $source, array $extra = []): array
    {
        $date ??= now();

        $frontmatter = [
            'schema' => FrontmatterSchema::CURRENT_VERSION,
            'type' => $type->value,
            'title' => $title,
            'date' => $date->toDateString(),
            'updated' => now()->toDateString(),
            'source' => $source,
            ...array_filter($extra, fn (mixed $value): bool => $value !== null && $value !== [] && $value !== ''),
        ];

        if ($type->requiresStatus() && ! isset($frontmatter['status'])) {
            $frontmatter['status'] = 'active';
        }

        return $this->schema->validate($frontmatter)->normalized;
    }

    /**
     * Completes an existing frontmatter so it honours the contract without
     * losing anything it already said — the type is inferred from the folder
     * when the note never carried one.
     *
     * @param  array<string, mixed>  $frontmatter
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function upgrade(array $frontmatter, string $relativePath, string $fallbackTitle, string $source, array $patch = []): array
    {
        $merged = [...$frontmatter, ...$patch];

        $type = NoteType::tryFrom((string) ($merged['type'] ?? ''))
            ?? NoteType::fromFolder(explode('/', $relativePath)[0])
            ?? NoteType::Note;

        $validated = $this->schema->validate($merged);
        $normalized = $validated->normalized;

        $extra = $normalized;
        unset($extra['schema'], $extra['type'], $extra['title'], $extra['date'], $extra['updated'], $extra['source']);

        $title = is_string($normalized['title'] ?? null) && trim($normalized['title']) !== ''
            ? trim($normalized['title'])
            : $fallbackTitle;

        $date = is_string($normalized['date'] ?? null) ? Carbon::parse($normalized['date']) : null;

        $existingSource = $normalized['source'] ?? null;
        $keepsSource = is_string($existingSource) && $this->schema->isValidSource($existingSource);

        return $this->build($type, $title, $date, $keepsSource ? $existingSource : $source, $extra);
    }
}
