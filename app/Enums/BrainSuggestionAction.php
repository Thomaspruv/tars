<?php

namespace App\Enums;

enum BrainSuggestionAction: string
{
    case Anchor = 'anchor';
    case Move = 'move';
    case Merge = 'merge';
    case Complete = 'complete';
    case Archive = 'archive';
    case CreateTask = 'create_task';
    case CreateListItem = 'create_list_item';
    case CreateGoal = 'create_goal';

    public function label(): string
    {
        return match ($this) {
            self::Anchor => 'Ancrer',
            self::Move => 'Déplacer',
            self::Merge => 'Fusionner',
            self::Complete => 'Compléter le frontmatter',
            self::Archive => 'Archiver',
            self::CreateTask => 'Créer une tâche',
            self::CreateListItem => 'Ajouter à une liste',
            self::CreateGoal => 'Créer un objectif',
        };
    }

    /**
     * Only these actions may ever be auto-applied — everything else always
     * requires a human decision in the Rangement tab.
     */
    public function isAutoApplicable(): bool
    {
        return in_array($this, [self::CreateTask, self::CreateListItem], true);
    }
}
