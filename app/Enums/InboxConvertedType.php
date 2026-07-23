<?php

namespace App\Enums;

enum InboxConvertedType: string
{
    case Task = 'task';
    case Event = 'event';
    case Goal = 'goal';
    case Note = 'note';
}
