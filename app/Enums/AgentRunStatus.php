<?php

namespace App\Enums;

enum AgentRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
