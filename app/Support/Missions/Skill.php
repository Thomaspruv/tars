<?php

namespace App\Support\Missions;

/**
 * Un skill installé par skills.sh, tel qu'on le lit sur le disque.
 */
class Skill
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $scope,
        public readonly string $path,
        public readonly ?string $source = null,
        public readonly ?string $sourceUrl = null,
        public readonly ?string $updatedAt = null,
    ) {}

    /**
     * La description d'un skill est écrite pour un agent, pas pour un lecteur :
     * elle énumère ses déclencheurs sur dix lignes. L'écran n'en montre que le
     * début, la fiche complète reste dans le SKILL.md.
     */
    public function shortDescription(int $limit = 180): string
    {
        if (mb_strlen($this->description) <= $limit) {
            return $this->description;
        }

        return mb_substr($this->description, 0, $limit).'…';
    }
}
