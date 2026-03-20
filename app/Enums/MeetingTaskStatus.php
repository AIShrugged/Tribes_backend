<?php

namespace App\Enums;

enum MeetingTaskStatus: string
{
    case OPEN        = 'open';
    case IN_PROGRESS = 'in_progress';
    case PAUSED      = 'paused';
    case DONE        = 'done';
}
