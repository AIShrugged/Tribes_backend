<?php

namespace App\Enums;

enum EmailStatus: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case SENT = 'sent';
    case FAILED = 'failed';
}
