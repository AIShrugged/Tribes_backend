<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Services\Meeting\MeetingReviewService;

class GenerateMeetingReview
{
    public function handle(TranscriptParsed $event): void
    {
        app(MeetingReviewService::class)->generate($event->calendarEvent);
    }
}
