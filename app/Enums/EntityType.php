<?php

namespace App\Enums;

enum EntityType: string
{
    case Company = 'company';
    case Property = 'property';
    case Vehicle = 'vehicle';
    case Other = 'other';
}
