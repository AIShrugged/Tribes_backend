<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Services\Meeting\MeetingTaskService;

class ExtractMeetingTasks
{
    public function handle(TranscriptParsed $event): void
    {
        app(MeetingTaskService::class)->extract($event->calendarEvent);
    }
}
