<?php

namespace App\Enums;

enum GitSyncStatus: string
{
    case Synced = 'synced';
    case Behind = 'behind';
    case Conflict = 'conflict';
    case Unknown = 'unknown';
}
