<?php

namespace App\Enums;

enum EntityType: string
{
    case Company = 'company';
    case Property = 'property';
    case Vehicle = 'vehicle';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Société',
            self::Property => 'Bien',
            self::Vehicle => 'Véhicule',
            self::Other => 'Autre',
        };
    }
}
