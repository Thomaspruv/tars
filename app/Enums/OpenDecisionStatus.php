<?php

namespace App\Enums;

enum OpenDecisionStatus: string
{
    case Open = 'open';
    case Decided = 'decided';
    case Dropped = 'dropped';
}
