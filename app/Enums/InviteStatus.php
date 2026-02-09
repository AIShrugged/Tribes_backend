<?php

namespace App\Enums;

enum InviteStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
}