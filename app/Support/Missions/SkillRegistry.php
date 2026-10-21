<?php

namespace App\Support\Missions;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

/**
 * Le registre public de skills.sh : chercher un skill qu'on n'a pas encore.
 *
 * Le registre n'expose qu'une recherche (`/api/search`), et elle ne renvoie ni
 * description ni mode d'emploi — un nom, un dépôt, un nombre d'installations.
 * La description se paie à la demande, par `skills use`, qui imprime le
 * SKILL.md sans l'installer : on ne la va chercher que pour le skill qu'on
 * regarde, pas pour les cent de la liste.
 */
class SkillRegistry
{
    private const string ENDPOINT = 'https://skills.sh/api/search';

    private const int SEARCH_CACHE_SECONDS = 600;

    private const int DESCRIPTION_CACHE_SECONDS = 86400;

    private const int RESULTS = 24;

    private const int SHORT_DESCRIPTION_CACHE_SECONDS = 604800;

    /** Une description qu'on n'a pas pu lire se redemande vite, pas dans sept jours. */
    private const int MISS_CACHE_SECONDS = 600;

    public function __construct(
        private readonly SkillsCli $cli = new SkillsCli,
        private readonly SkillCatalog $catalog = new SkillCatalog,
    ) {}

    /**
     * @return list<RegistrySkill>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        // Le registre refuse en dessous de deux signes : autant ne pas
        // l'appeler pour se faire dire non.
        if (mb_strlen($query) < 2) {
            return [];
        }

        $rows = Cache::remember(
            'missions.registry.'.md5(mb_strtolower($query)),
            self::SEARCH_CACHE_SECONDS,
            fn (): array => $this->fetch($query),
        );

        $installed = array_map(fn (Skill $skill): string => $skill->slug, $this->catalog->all());

        $skills = array_map(fn (array $row): RegistrySkill => new RegistrySkill(
            source: (string) $row['source'],
            skillId: (string) $row['skillId'],
            name: (string) $row['name'],
            installs: (int) $row['installs'],
            installed: in_array($row['skillId'], $installed, true),
        ), $rows);

        return $this->withShortDescriptions($skills);
    }

    /**
     * La description courte de chaque résultat, en un seul aller-retour.
     *
     * L'API de recherche n'en donne pas, et la demander skill par skill au CLI
     * coûte une seconde chacun — inutilisable pour une liste. Mais la page
     * publique d'un skill porte la sienne dans sa balise `<meta>`, déjà
     * tronquée pour un aperçu : on va chercher les pages ensemble, et on les
     * garde une semaine.
     *
     * @param  list<RegistrySkill>  $skills
     * @return list<RegistrySkill>
     */
    private function withShortDescriptions(array $skills): array
    {
        $missing = [];

        foreach ($skills as $skill) {
            if (! Cache::has($this->shortKey($skill))) {
                $missing[$skill->id()] = $skill;
            }
        }

        if ($missing !== []) {
            $this->fetchShortDescriptions($missing);
        }

        return array_map(function (RegistrySkill $skill): RegistrySkill {
            $description = Cache::get($this->shortKey($skill));

            return $skill->withDescription(is_string($description) && $description !== '' ? $description : null);
        }, $skills);
    }

    /**
     * @param  array<string, RegistrySkill>  $skills
     */
    private function fetchShortDescriptions(array $skills): void
    {
        try {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (RegistrySkill $skill) => $pool->as($skill->id())->timeout(12)->get($this->pageUrl($skill)),
                $skills,
            ));
        } catch (\Throwable) {
            return;
        }

        foreach ($skills as $id => $skill) {
            $response = $responses[$id] ?? null;

            $description = $response instanceof Response && $response->successful()
                ? $this->metaDescription($response->body())
                : null;

            Cache::put(
                $this->shortKey($skill),
                $description ?? '',
                $description === null ? self::MISS_CACHE_SECONDS : self::SHORT_DESCRIPTION_CACHE_SECONDS,
            );
        }
    }

    private function pageUrl(RegistrySkill $skill): string
    {
        return 'https://skills.sh/'.$skill->source.'/'.$skill->skillId;
    }

    private function shortKey(RegistrySkill $skill): string
    {
        return 'missions.registry.short.'.md5($skill->id());
    }

    private function metaDescription(string $html): ?string
    {
        foreach (['name="description"', 'property="og:description"'] as $attribute) {
            if (preg_match('/<meta[^>]+'.preg_quote($attribute, '/').'[^>]*content="([^"]*)"/i', $html, $matches) !== 1) {
                continue;
            }

            $description = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($description !== '') {
                return $description;
            }
        }

        return null;
    }

    /**
     * Ce qu'un skill du registre fait, sans l'installer — ou pourquoi on n'a
     * pas pu le dire.
     *
     * Les trois issues sont distinguées parce qu'elles n'appellent pas la même
     * décision : une fiche périmée (le skill a quitté son dépôt) ne vaut pas la
     * peine d'être installée, un SKILL.md sans description le vaut peut-être
     * quand même, et un CLI muet n'apprend rien sur le skill.
     *
     * @return array{description: string|null, note: string|null}
     */
    public function describe(string $source, string $skillId): array
    {
        if (! SkillCatalog::isValidPackage($source) || ! preg_match('/^[A-Za-z0-9_.-]+$/', $skillId)) {
            return ['description' => null, 'note' => 'Nom de dépôt ou de skill invalide.'];
        }

        $key = 'missions.registry.description.'.md5($source.'@'.$skillId);

        // Un cache tiède peut porter la forme d'une version précédente du code
        // — ici une simple chaîne, avant qu'on distingue la description de la
        // raison de son absence. On ne fait pas confiance à ce qu'on relit :
        // mal formé, on le jette et on recalcule.
        $cached = Cache::get($key);

        if ($cached !== null && ! $this->isDescription($cached)) {
            Cache::forget($key);
        }

        /** @var array{description: string|null, note: string|null} */
        return Cache::remember(
            $key,
            self::DESCRIPTION_CACHE_SECONDS,
            function () use ($source, $skillId): array {
                $result = $this->cli->run(['use', $source.'@'.$skillId], timeout: 120);

                if (! $result['ok']) {
                    return [
                        'description' => null,
                        'note' => str_contains($result['output'], 'No matching skill found')
                            ? "Ce skill n'est plus dans son dépôt : la fiche du registre a vieilli."
                            : "Impossible de lire ce skill : le CLI skills.sh n'a pas répondu.",
                    ];
                }

                $description = $this->descriptionOf($result['output']);

                return [
                    'description' => $description,
                    'note' => $description === null ? 'Son SKILL.md ne porte pas de description.' : null,
                ];
            },
        );
    }

    private function isDescription(mixed $value): bool
    {
        return is_array($value)
            && array_key_exists('description', $value)
            && array_key_exists('note', $value);
    }

    /**
     * Le mode d'emploi complet d'un skill du registre, sans l'installer.
     *
     * `skills use` l'imprime entre deux balises `<SKILL.md>` : c'est ce qui
     * permet à un agent d'aller chercher un skill et de l'appliquer dans le
     * tour même, sans attendre une installation ni un redémarrage.
     */
    public function markdown(string $source, string $skillId): ?string
    {
        if (! SkillCatalog::isValidPackage($source) || ! preg_match('/^[A-Za-z0-9_.-]+$/', $skillId)) {
            return null;
        }

        /** @var string|null */
        return Cache::remember(
            'missions.registry.markdown.'.md5($source.'@'.$skillId),
            self::DESCRIPTION_CACHE_SECONDS,
            function () use ($source, $skillId): ?string {
                $result = $this->cli->run(['use', $source.'@'.$skillId], timeout: 120);

                if (! $result['ok']) {
                    return null;
                }

                return preg_match('#<SKILL\.md>\s*(.*?)\s*</SKILL\.md>#s', $result['output'], $matches) === 1
                    ? trim($matches[1])
                    : null;
            },
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(string $query): array
    {
        try {
            $response = Http::timeout(15)->acceptJson()->get(self::ENDPOINT, ['q' => $query]);

            if (! $response->successful()) {
                return [];
            }

            $skills = $response->json('skills');
        } catch (\Throwable) {
            // Le registre est un service tiers : qu'il soit injoignable ne doit
            // pas casser l'écran, les skills installés restent là.
            return [];
        }

        if (! is_array($skills)) {
            return [];
        }

        $rows = [];

        foreach (array_slice($skills, 0, self::RESULTS) as $skill) {
            if (is_array($skill) && isset($skill['source'], $skill['skillId'], $skill['name'])) {
                $rows[] = [
                    'source' => $skill['source'],
                    'skillId' => $skill['skillId'],
                    'name' => $skill['name'],
                    'installs' => $skill['installs'] ?? 0,
                ];
            }
        }

        return $rows;
    }

    /**
     * `skills use` imprime le SKILL.md entre balises, frontmatter compris.
     */
    private function descriptionOf(string $output): ?string
    {
        if (! preg_match('/^---\r?\n(.*?)\r?\n---/ms', $output, $matches)) {
            return null;
        }

        try {
            $parsed = Yaml::parse($matches[1]);
        } catch (\Throwable) {
            return null;
        }

        $description = is_array($parsed) ? ($parsed['description'] ?? null) : null;

        return is_string($description) && trim($description) !== '' ? trim($description) : null;
    }
}
