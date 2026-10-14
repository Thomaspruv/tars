<?php

namespace App\Enums;

enum SuggestionConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * Le seuil du « Tout valider » : au-dessus, la proposition se pose d'un ✓
     * en lot ; en dessous, l'agent dit qu'il n'est pas sûr et me laisse le
     * choix du type.
     */
    public function isActionable(): bool
    {
        return $this !== self::Low;
    }
}
