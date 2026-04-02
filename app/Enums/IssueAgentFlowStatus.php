<?php

namespace App\Enums;

enum IssueAgentFlowStatus: string
{
    case PLANNING = 'planning';
    case RUNNING = 'running';
    case BLOCKED = 'blocked';
    case WAITING_FOR_USER = 'waiting_for_user';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
