<?php

namespace App\Enums;

enum AgentWorkspaceKind: string
{
    case Vault = 'vault';
    case Repo = 'repo';

    public function label(): string
    {
        return match ($this) {
            self::Vault => 'le cerveau',
            self::Repo => 'un dépôt de code',
        };
    }
}
