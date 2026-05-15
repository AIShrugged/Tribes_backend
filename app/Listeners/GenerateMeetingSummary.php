<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Services\Meeting\MeetingSummaryService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMeetingSummary implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function handle(TranscriptParsed $event): void
    {
        app(MeetingSummaryService::class)->generate($event->calendarEvent);
    }
}
