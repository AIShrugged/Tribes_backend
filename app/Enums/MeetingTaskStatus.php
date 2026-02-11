<?php

namespace App\Enums;

enum MeetingTaskStatus: string
{
    case OPEN        = 'open';
    case IN_PROGRESS = 'in_progress';
    case DONE        = 'done';
    case CANCELLED   = 'cancelled';
}
