<?php

namespace App\Enums;

enum GoalStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Done = 'done';
    case Abandoned = 'abandoned';
}
