<?php

namespace App\Enums;

enum BrainSuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case AutoApplied = 'auto_applied';
}
