<?php

namespace App\Enums;

enum DecisionSource: string
{
    case Review = 'review';
    case Conversation = 'conversation';
    case Arbitration = 'arbitration';
}
