<?php

namespace App\Support\Momentum;

use App\Models\Goal;

/**
 * L'état d'un objectif dans la bande d'état : son niveau de jauge, et depuis
 * combien de semaines il n'a rien reçu.
 */
final readonly class GaugeReading
{
    public function __construct(
        public Goal $goal,
        public int $level,
        public int $weeksIdle,
    ) {}

    /**
     * La teinte de la jauge ne dit pas *quel* objectif c'est (ça, c'est la
     * palette catégorielle) mais *comment il va*. Le blé est le seul signal
     * d'alerte du système : jamais de rouge, jamais de clignotement.
     */
    public function tone(): string
    {
        return match (true) {
            $this->level >= 60 => 'sprout',
            $this->level >= 30 => 'linden',
            default => 'wheat',
        };
    }

    /**
     * « 3 sem. » à côté du nom du domaine — seulement à partir de deux
     * semaines sans rien, pour que la mention garde du sens.
     */
    public function idleLabel(): ?string
    {
        return $this->weeksIdle >= 2 ? $this->weeksIdle.' sem.' : null;
    }
}
