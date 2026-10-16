<?php

namespace App\Support\Brain;

use App\Support\Brain\Schema\NoteType;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Turns a note's *type* into its *place* in the vault — the target tree of
 * SPEC-TARS-OS-v1 §A.4.
 *
 * The path of a machine-written note is always computed here and never
 * supplied by the caller: that is what keeps Hermes from inventing folders.
 */
class NotePathResolver
{
    public function __construct(private readonly BrainSettings $settings) {}

    /**
     * @param  array{subject?: ?string, project?: ?string}  $options
     */
    public function resolve(NoteType $type, string $title, ?CarbonInterface $date = null, array $options = []): string
    {
        $date = $date?->copy() ?? now();
        $slug = Str::slug($title) ?: 'note';
        $project = $this->slugOrNull($options['project'] ?? null);

        if ($type === NoteType::Project) {
            return "Projets/{$slug}/README.md";
        }

        if ($type === NoteType::Journal) {
            return "Journal/{$date->format('Y')}/{$date->format('Y-m-d')}.md";
        }

        $folder = match (true) {
            $type === NoteType::Veille => 'Veille'.($this->slugOrNull($options['subject'] ?? null) !== null ? '/'.$this->slugOrNull($options['subject']) : ''),
            // Prepared for the Projets iteration: a project's dated notes live
            // alongside its brief rather than in the transverse folders.
            $project !== null && in_array($type, [NoteType::Meeting, NoteType::Note, NoteType::Decision], true) => "Projets/{$project}/notes",
            default => $type->folder(),
        };

        $filename = $type->isDated()
            ? "{$date->format('Y-m-d')}-{$slug}.md"
            : "{$slug}.md";

        return $this->withoutCollision("{$folder}/{$filename}", $type);
    }

    /**
     * A living note at an existing path is an update (same path, merged in
     * place). A dated note is immutable: a second one the same day gets a
     * `-2` suffix rather than overwriting the first.
     */
    private function withoutCollision(string $relativePath, NoteType $type): string
    {
        if (! $type->isDated() || ! File::exists($this->absolute($relativePath))) {
            return $relativePath;
        }

        $base = Str::beforeLast($relativePath, '.md');

        for ($suffix = 2; $suffix <= 99; $suffix++) {
            $candidate = "{$base}-{$suffix}.md";

            if (! File::exists($this->absolute($candidate))) {
                return $candidate;
            }
        }

        return "{$base}-".now()->format('His').'.md';
    }

    private function absolute(string $relativePath): string
    {
        return rtrim($this->settings->localPath(), '/')."/{$relativePath}";
    }

    private function slugOrNull(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Str::slug($value) ?: null;
    }
}
