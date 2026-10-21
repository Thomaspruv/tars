<?php

namespace App\Enums;

enum MissionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'en attente',
            self::Running => 'en cours',
            self::Succeeded => 'terminée',
            self::Failed => 'échouée',
            self::Cancelled => 'arrêtée',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    /**
     * Le ton de la pastille, dans le vocabulaire du système de design : le vert
     * est la couleur de l'agent, l'abricot la mienne, et seul un échec sort du
     * registre calme.
     *
     * @return 'agent'|'sunk'|'warn'
     */
    public function tone(): string
    {
        return match ($this) {
            self::Running => 'agent',
            self::Failed => 'warn',
            default => 'sunk',
        };
    }
}
