<?php

namespace App\Support\Brain\Schema;

use Illuminate\Support\Carbon;

/**
 * The frontmatter contract (v3) — SPEC-TARS-OS-v1 §A.5.
 *
 * Validation never throws: a note written before the contract existed is
 * *non conformant but tolerated*. The consequence of non-conformance is
 * documented in VaultIndexer (no anchors resolved) and in `/cerveau` (a
 * badge), not an exception.
 */
class FrontmatterSchema
{
    public const int CURRENT_VERSION = 3;

    public const array STATUSES = ['active', 'paused', 'done', 'archived'];

    /**
     * Zones no one but their owner curates: Thomas's profile, and Hermes's own
     * technical memory. Always indexed, anchors always resolved, never flagged
     * as non conformant.
     */
    public const array EXEMPT_FOLDERS = ['Profil', '.hermes'];

    /**
     * Files TARS generates itself (the vault's README and every `_index.md`).
     * They carry a conformant frontmatter, but their *location* is dictated by
     * the folder they describe rather than by their type.
     */
    public static function isGenerated(string $relativePath): bool
    {
        return $relativePath === 'README.md' || basename($relativePath) === '_index.md';
    }

    public static function isExempt(string $relativePath): bool
    {
        return in_array(explode('/', $relativePath)[0], self::EXEMPT_FOLDERS, true);
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function validate(array $frontmatter): SchemaValidationResult
    {
        $errors = [];
        $normalized = $frontmatter;

        $schemaVersion = $this->intOrNull($frontmatter['schema'] ?? null);

        if ($schemaVersion === null) {
            $errors[] = 'Champ `schema` manquant — le contrat attendu est `schema: '.self::CURRENT_VERSION.'`.';
        } elseif ($schemaVersion !== self::CURRENT_VERSION) {
            $errors[] = "Version de contrat `schema: {$schemaVersion}` obsolète — la version courante est ".self::CURRENT_VERSION.'.';
        } else {
            $normalized['schema'] = $schemaVersion;
        }

        $type = $this->stringOrNull($frontmatter['type'] ?? null);
        $noteType = $type !== null ? NoteType::tryFrom($type) : null;

        if ($type === null) {
            $errors[] = 'Champ `type` manquant.';
        } elseif ($noteType === null) {
            $errors[] = "Type `{$type}` inconnu — les types valides sont ".$this->typeList().'.';
        }

        if ($this->stringOrNull($frontmatter['title'] ?? null) === null) {
            $errors[] = 'Champ `title` manquant.';
        }

        foreach (['date', 'updated', 'archived'] as $key) {
            if (! array_key_exists($key, $frontmatter) || $frontmatter[$key] === null) {
                if ($key === 'date') {
                    $errors[] = 'Champ `date` manquant.';
                }

                continue;
            }

            $date = $this->isoDate($frontmatter[$key]);

            if ($date === null) {
                $errors[] = "Champ `{$key}` : date illisible, le format attendu est AAAA-MM-JJ.";

                continue;
            }

            $normalized[$key] = $date;
        }

        $status = $this->stringOrNull($frontmatter['status'] ?? null);

        if ($status !== null && ! in_array($status, self::STATUSES, true)) {
            $errors[] = "Statut `{$status}` inconnu — les statuts valides sont ".implode(', ', self::STATUSES).'.';
        }

        if ($status === null && $noteType?->requiresStatus()) {
            $errors[] = "Champ `status` requis pour le type `{$noteType->value}`.";
        }

        foreach (['entity', 'goal'] as $key) {
            if (! array_key_exists($key, $frontmatter)) {
                continue;
            }

            $list = $this->stringList($frontmatter[$key]);

            if ($list === null) {
                $errors[] = "Champ `{$key}` : un nom ou une liste de noms est attendu.";

                continue;
            }

            $normalized[$key] = $list;
        }

        $source = $this->stringOrNull($frontmatter['source'] ?? null);

        if ($source !== null && ! $this->isValidSource($source)) {
            $errors[] = "Source `{$source}` invalide — attendu : thomas, hermes, agent-<nom> ou connector-<nom>.";
        }

        return new SchemaValidationResult(
            isConformant: $errors === [],
            schemaVersion: $schemaVersion,
            errors: $errors,
            normalized: $normalized,
            noteType: $noteType,
            status: $status,
        );
    }

    public function isValidSource(string $source): bool
    {
        return in_array($source, ['thomas', 'hermes'], true)
            || (bool) preg_match('/^(agent|connector)-[\w-]+$/u', $source);
    }

    private function typeList(): string
    {
        return implode(', ', array_map(fn (NoteType $case): string => $case->value, NoteType::cases()));
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return list<string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (is_string($value)) {
            return trim($value) === '' ? [] : [trim($value)];
        }

        if (! is_array($value)) {
            return null;
        }

        $items = array_values(array_filter(array_map(fn (mixed $item): ?string => $this->stringOrNull($item), $value)));

        return count($items) === count($value) ? $items : null;
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // An unquoted `date: 2026-10-16` in Obsidian comes back from the YAML
        // parser as a Unix timestamp, not a string — that's the common case in
        // a hand-written note, so it is a valid ISO date, not an error.
        if (is_int($value)) {
            return Carbon::createFromTimestampUTC($value)->toDateString();
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value))) {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
