<?php

namespace App\Enums;

enum AgentTaskRunStatus: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case PAUSED = 'paused';
}
