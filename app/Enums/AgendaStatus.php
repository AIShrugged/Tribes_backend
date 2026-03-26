<?php

namespace App\Enums;

enum AgendaStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case FAILED = 'failed';
}
