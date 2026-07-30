<?php

namespace App\Enums;

enum QuestionnaireFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'hebdomadaire',
            self::Monthly => 'mensuel',
            self::Quarterly => 'trimestriel',
            self::Yearly => 'annuel',
        };
    }
}
