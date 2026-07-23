<?php

namespace App\Enums;

enum MilestoneStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
}
