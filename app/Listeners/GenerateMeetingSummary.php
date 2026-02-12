<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Services\Meeting\MeetingSummaryService;

class GenerateMeetingSummary
{
    public function handle(TranscriptParsed $event): void
    {
        app(MeetingSummaryService::class)->generate($event->calendarEvent);
    }
}
