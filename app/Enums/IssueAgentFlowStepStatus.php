<?php

namespace App\Enums;

enum IssueAgentFlowStepStatus: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case WAITING_FOR_USER = 'waiting_for_user';
}
