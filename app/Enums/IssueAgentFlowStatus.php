<?php

namespace App\Enums;

enum IssueAgentFlowStatus: string
{
    case PLANNING = 'planning';
    case RUNNING = 'running';
    case BLOCKED = 'blocked';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
