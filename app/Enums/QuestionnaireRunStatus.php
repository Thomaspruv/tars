<?php

namespace App\Enums;

enum QuestionnaireRunStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'en attente',
            self::Completed => 'complété',
        };
    }
}
