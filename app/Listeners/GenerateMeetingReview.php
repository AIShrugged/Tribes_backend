<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Services\Extraction\ExtractionPlanCoordinator;
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
        $calendarEvent = $event->calendarEvent;

        // Moderated upload (a plan exists): generate the review but DEFER its Telegram notification —
        // ApproveExtractionPlanService re-dispatches MeetingReviewGenerated on approve. Gate on
        // hasActivePlan (collecting OR pending_review), NOT hasCollectingPlan: this listener can run
        // after the barrier flips, and checking only 'collecting' would leak a premature notification.
        // Recall (no plan) keeps dispatching immediately (default true).
        $gated = app(ExtractionPlanCoordinator::class)->hasActivePlan($calendarEvent);

        app(MeetingReviewService::class)->generate($calendarEvent, dispatchNotification: ! $gated);
    }
}
