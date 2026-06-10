<?php

namespace App\Listeners;

use App\Enums\FollowupStatus;
use App\Events\TranscriptParsed;
use App\Services\Extraction\ExtractionPlanCoordinator;
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
        $calendarEvent = $event->calendarEvent;

        app(MeetingSummaryService::class)->generate($calendarEvent);

        // Moderated-upload anti-strand: MeetingSummaryService swallows LLM errors, sets the summary
        // FAILED, and does NOT dispatch MeetingSummaryGenerated — so the decisions branch would never
        // run and the barrier would wait forever. Fail the decisions section explicitly here.
        // No collecting plan (Recall, or success that already staged decisions) → no-op.
        $coordinator = app(ExtractionPlanCoordinator::class);
        if (! $coordinator->hasCollectingPlan($calendarEvent)) {
            return;
        }

        $summary = $calendarEvent->meetingSummary()->first();
        if (! $summary || $summary->status === FollowupStatus::FAILED->value) {
            $coordinator->markSectionFailed($calendarEvent, 'decisions');
        }
    }
}
