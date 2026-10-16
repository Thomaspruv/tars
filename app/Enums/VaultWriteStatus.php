<?php

namespace App\Enums;

enum VaultWriteStatus: string
{
    case Written = 'written';
    case Conflict = 'conflict';
    case Invalid = 'invalid';
    case Unconfigured = 'unconfigured';
}
