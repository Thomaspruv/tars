<?php

namespace App\Enums;

enum WatchEnded: string
{
    case Found = 'found';
    case Expired = 'expired';
    case Exhausted = 'exhausted';
    case Stopped = 'stopped';
    case Failing = 'failing';
    case TooExpensive = 'too_expensive';

    public function label(): string
    {
        return match ($this) {
            self::Found => 'il a trouvé',
            self::Expired => 'le délai est passé',
            self::Exhausted => 'il a atteint sa limite de passages',
            self::Stopped => 'je l\'ai arrêtée',
            self::Failing => 'elle échouait à répétition',
            self::TooExpensive => 'elle avait atteint son plafond de dépense',
        };
    }
}
