<?php

namespace App\Enums;

enum AgentRunTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
