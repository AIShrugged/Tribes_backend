<?php

namespace App\Enums;

enum MeetingTaskStatus: string
{
    case OPEN        = 'open';
    case IN_PROGRESS = 'in_progress';
    case PAUSED      = 'paused';
    case REVIEWED    = 'reviewed';
    case REVIEW      = 'review';
    case REOPEN      = 'reopen';
    case DONE        = 'done';
}