<?php

namespace App\Listeners;

use App\Events\MeetingSummaryGenerated;
use App\Models\CalendarEvent;
use App\Models\MeetingSummary;
use App\Services\Decisions\ExtractDecisionsService;
use App\Services\Extraction\ExtractionPlanCoordinator;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractDecisionsAfterSummary implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly ExtractDecisionsService $extractor,
    ) {}

    public function handle(MeetingSummaryGenerated $event): void
    {
        $summary = $event->summary;
        $calendarEvent = $summary->calendarEvent;

        // Gated: stage the enriched decisions into the collecting plan instead of persisting.
        // hasCollectingPlan() is the re-entrancy guard — a post-approve summary regeneration must
        // NOT re-stage into an already-approved plan; it falls through to the normal extract() below.
        if ($this->isGated($calendarEvent)) {
            $this->stageDecisionsPlan($summary, $calendarEvent);

            return;
        }

        try {
            $count = $this->extractor->extract($summary);

            if ($count > 0) {
                Log::info('ExtractDecisionsAfterSummary: extracted decisions', [
                    'summary_id' => $summary->id,
                    'count'      => $count,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ExtractDecisionsAfterSummary: failed', [
                'summary_id' => $summary->id,
                'error'      => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** Anti-strand: a crashed gated compute must fail the plan section, not leave the barrier collecting. */
    public function failed(MeetingSummaryGenerated $event, \Throwable $e): void
    {
        $calendarEvent = $event->summary->calendarEvent;
        if ($calendarEvent && $this->isGated($calendarEvent)) {
            app(ExtractionPlanCoordinator::class)->markSectionFailed($calendarEvent, 'decisions');
        }
    }

    /**
     * Moderated iff a plan exists for the event (created by the manual upload controller). Gate on
     * EXISTENCE: this also makes post-approve summary regeneration safe — the plan is then 'approved',
     * markSectionReady no-ops, and the already-approved decisions are NOT re-extracted/wiped.
     * Platform-agnostic; Recall has no plan.
     */
    private function isGated(?CalendarEvent $calendarEvent): bool
    {
        return $calendarEvent !== null
            && app(ExtractionPlanCoordinator::class)->hasPlan($calendarEvent);
    }

    private function stageDecisionsPlan(MeetingSummary $summary, CalendarEvent $calendarEvent): void
    {
        $plan = app(ExtractDecisionsService::class)->computeDecisionPlan($summary);

        $coordinator = app(ExtractionPlanCoordinator::class);
        $flip = $coordinator->markSectionReady($calendarEvent, 'decisions', ['items' => $plan['items'] ?? []]);
        $coordinator->finalizeFlip($calendarEvent, $flip);
    }
}
