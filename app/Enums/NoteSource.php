<?php

namespace App\Enums;

enum NoteSource: string
{
    case User = 'user';
    case Agent = 'agent';
}
