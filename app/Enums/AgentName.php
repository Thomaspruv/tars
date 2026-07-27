<?php

namespace App\Enums;

enum AgentName: string
{
    case Reviewer = 'reviewer';
    case Curateur = 'curateur';
    case Triage = 'triage';
    case Planner = 'planner';

    public function label(): string
    {
        return match ($this) {
            self::Reviewer => 'Reviewer',
            self::Curateur => 'Curateur',
            self::Triage => 'Triage',
            self::Planner => 'Planner',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Reviewer => 'Génère la revue hebdomadaire et mensuelle.',
            self::Curateur => 'Traite la todo du sas chaque heure, range le cerveau chaque soir.',
            self::Triage => "Propose le classement des items de l'inbox.",
            self::Planner => 'Répartit les tâches de la semaine à venir.',
        };
    }

    public function isAvailable(): bool
    {
        return in_array($this, [self::Reviewer, self::Curateur], true);
    }
}
