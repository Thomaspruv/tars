<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Inbox = 'inbox';
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
