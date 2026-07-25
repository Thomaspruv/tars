<?php

namespace App\Enums;

enum McpCallStatus: string
{
    case Success = 'success';
    case Error = 'error';
    case Ambiguous = 'ambiguous';
}
