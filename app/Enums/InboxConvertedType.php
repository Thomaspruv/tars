<?php

namespace App\Enums;

enum InboxConvertedType: string
{
    case Task = 'task';
    case Event = 'event';
    case Goal = 'goal';
    case Note = 'note';

    public function label(): string
    {
        return match ($this) {
            self::Task => 'Tâche',
            self::Event => 'Événement',
            self::Goal => 'Objectif',
            self::Note => 'Note',
        };
    }
}
