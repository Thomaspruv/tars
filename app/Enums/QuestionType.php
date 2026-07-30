<?php

namespace App\Enums;

enum QuestionType: string
{
    case Scale = 'scale';
    case Text = 'text';
    case Boolean = 'boolean';
    case Number = 'number';

    public function label(): string
    {
        return match ($this) {
            self::Scale => 'note /10',
            self::Text => 'texte',
            self::Boolean => 'oui/non',
            self::Number => 'nombre',
        };
    }
}
