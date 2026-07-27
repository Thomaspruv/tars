<?php

namespace App\Enums;

enum EntityRelationType: string
{
    case EmployedBy = 'employed_by';
    case SubsidiaryOf = 'subsidiary_of';
    case OwnerOf = 'owner_of';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::EmployedBy => 'employé de',
            self::SubsidiaryOf => 'filiale de',
            self::OwnerOf => 'propriétaire de',
            self::Other => 'autre',
        };
    }
}
