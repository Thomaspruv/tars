<?php

namespace App\Support\Missions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Les skills installés sur cette machine, lus là où skills.sh les pose.
 *
 * On lit le disque plutôt que la sortie de `npx skills list` : c'est instantané,
 * ça ne dépend pas d'une version de Node, et le format d'un SKILL.md (un
 * frontmatter YAML avec `name` et `description`) est le contrat de l'écosystème.
 * Le fichier de verrou de skills.sh ne sert qu'à nommer la provenance.
 */
class SkillCatalog
{
    private const string CACHE_KEY = 'missions.skills';

    private const int CACHE_SECONDS = 300;

    /**
     * @return list<Skill>
     */
    public function all(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        // Le cache a déjà porté des objets `Skill`, que le worker relisait en
        // objets incomplets. On vérifie ce qu'on relit plutôt que de supposer
        // que c'est cette version du code qui l'a écrit.
        if ($cached !== null && ! $this->isRows($cached)) {
            Cache::forget(self::CACHE_KEY);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->scan());

        return array_map(fn (array $row): Skill => new Skill(
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            description: (string) $row['description'],
            scope: (string) $row['scope'],
            path: (string) $row['path'],
            source: $row['source'] === null ? null : (string) $row['source'],
            sourceUrl: $row['sourceUrl'] === null ? null : (string) $row['sourceUrl'],
            updatedAt: $row['updatedAt'] === null ? null : (string) $row['updatedAt'],
        ), $rows);
    }

    private function isRows(mixed $value): bool
    {
        return is_array($value) && (count($value) === 0 || is_array(reset($value)));
    }

    public function find(string $slug): ?Skill
    {
        foreach ($this->all() as $skill) {
            if ($skill->slug === $slug) {
                return $skill;
            }
        }

        return null;
    }

    /**
     * Le mode d'emploi complet d'un skill installé, tel qu'il est sur le
     * disque.
     */
    public function markdown(string $slug): ?string
    {
        $skill = $this->find($slug);

        return $skill !== null && File::exists($skill->path) ? File::get($skill->path) : null;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Le cache ne porte que des tableaux : un objet mis en cache revient en
     * objet incomplet dans le worker de queue (mesuré, et c'est une mission
     * perdue avant d'avoir commencé).
     *
     * @return list<array<string, mixed>>
     */
    private function scan(): array
    {
        $lock = $this->lock();
        $skills = [];
        $seen = [];

        /** @var array<string, string> $paths */
        $paths = config('missions.skill_paths', []);

        foreach ($paths as $scope => $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::directories($root) as $directory) {
                $file = $directory.'/SKILL.md';

                if (! File::exists($file)) {
                    continue;
                }

                $slug = basename($directory);

                // Un skill installé dans le projet masque son homonyme global :
                // c'est l'ordre dans lequel Claude Code les résout.
                if (isset($seen[$slug])) {
                    continue;
                }

                $seen[$slug] = true;
                $frontmatter = $this->frontmatter(File::get($file));
                $entry = $lock[$slug] ?? [];

                $skills[] = [
                    'slug' => $slug,
                    'name' => (string) ($frontmatter['name'] ?? $slug),
                    'description' => trim((string) ($frontmatter['description'] ?? '')),
                    'scope' => (string) $scope,
                    'path' => $file,
                    'source' => isset($entry['source']) ? (string) $entry['source'] : null,
                    'sourceUrl' => isset($entry['sourceUrl']) ? (string) $entry['sourceUrl'] : null,
                    'updatedAt' => isset($entry['updatedAt']) ? (string) $entry['updatedAt'] : null,
                ];
            }
        }

        usort($skills, fn (array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));

        return $skills;
    }

    /**
     * Même découpe que `VaultParser::splitFrontmatter()`, sans traîner avec elle
     * le schéma du cerveau : un SKILL.md ne doit rien au contrat du vault.
     *
     * @return array<string, mixed>
     */
    private function frontmatter(string $raw): array
    {
        if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?/us', $raw, $matches)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($matches[1]);
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * La provenance, telle que skills.sh la note dans son fichier de verrou.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lock(): array
    {
        $path = (string) config('missions.skill_lock_path');

        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode(File::get($path), true);

        if (! is_array($decoded) || ! isset($decoded['skills']) || ! is_array($decoded['skills'])) {
            return [];
        }

        /** @var array<string, array<string, mixed>> */
        return array_filter($decoded['skills'], 'is_array');
    }

    /**
     * Le nom du dépôt est tapé à la main dans l'écran Skills : on vérifie sa
     * forme ici plutôt que de la confier à une ligne de commande.
     */
    public static function isValidPackage(string $package): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', trim($package))
            && ! Str::contains($package, '..');
    }
}
