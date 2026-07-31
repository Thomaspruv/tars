<?php

namespace App\Enums;

enum PlannerProposalStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Dismissed = 'dismissed';
}
