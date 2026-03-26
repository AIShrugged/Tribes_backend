<?php

namespace App\Enums;

enum BotEventType: string
{
    case SCHEDULED = 'scheduled';
    case JOINED = 'joined';
    case REMOVED = 'removed';
    case KICKED = 'kicked';
    case TRANSCRIPT_DONE = 'transcript_done';
}
