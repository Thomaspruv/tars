<?php

namespace App\Support\Missions;

/**
 * Un skill tel que le registre skills.sh le connaît : pas encore installé,
 * donc connu seulement par son nom, son dépôt et sa popularité.
 */
class RegistrySkill
{
    public function __construct(
        public readonly string $source,
        public readonly string $skillId,
        public readonly string $name,
        public readonly int $installs,
        public readonly bool $installed = false,
        public readonly ?string $description = null,
    ) {}

    public function id(): string
    {
        return $this->source.'/'.$this->skillId;
    }

    /**
     * Le nombre d'installations est le seul jugement que le registre porte sur
     * un skill : on l'affiche court, parce qu'on ne compare que des ordres de
     * grandeur.
     */
    public function installsLabel(): string
    {
        return match (true) {
            $this->installs >= 1000000 => round($this->installs / 1000000, 1).' M',
            $this->installs >= 1000 => round($this->installs / 1000).' k',
            default => (string) $this->installs,
        };
    }

    public function withDescription(?string $description): self
    {
        return new self($this->source, $this->skillId, $this->name, $this->installs, $this->installed, $description);
    }
}
