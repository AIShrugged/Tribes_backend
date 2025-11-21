<?php

namespace App\Enums;

enum SourceType: string
{
    case GOOGLE_CALENDAR = 'google_calendar';
    case MICROSOFT_OUTLOOK = 'microsoft_outlook';
}
