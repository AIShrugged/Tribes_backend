<?php

namespace App\Enums;

enum FollowupStatus: string
{
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case FAILED = 'failed';
}
