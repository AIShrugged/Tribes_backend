<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Jobs\GenerateMeetingTaskReviewJob;
use App\Jobs\PruneResolvedMeetingTaskItemsJob;
use App\Services\CalendarEventOrganizationResolver;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateMeetingTaskReview implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function handle(TranscriptParsed $event): void
    {
        $calendarEvent = $event->calendarEvent;
        $orgId = app(CalendarEventOrganizationResolver::class)
            ->resolveOrganizationId($calendarEvent);

        if (!$orgId) {
            Log::info("MeetingTaskReview: no org for event {$calendarEvent->id}, skipping");

            return;
        }

        GenerateMeetingTaskReviewJob::dispatch($calendarEvent, $orgId);
        PruneResolvedMeetingTaskItemsJob::dispatch($orgId);
    }
}
