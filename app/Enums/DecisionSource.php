<?php

namespace App\Enums;

enum DecisionSource: string
{
    case Review = 'review';
    case Conversation = 'conversation';
}
