<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Services\Meeting\MeetingReviewService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMeetingReview implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function handle(TranscriptParsed $event): void
    {
        app(MeetingReviewService::class)->generate($event->calendarEvent);
    }
}
