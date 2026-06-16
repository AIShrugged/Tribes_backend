<?php

namespace App\Jobs;

use App\Events\MeetingArtifactsReady;
use App\Models\CalendarEvent;
use App\Services\Issue\IssueConflictDetector;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DetectIssueConflictsJob implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    /**
     * @param  int[]  $newIssueIds
     */
    public function __construct(
        public array $newIssueIds,
        public ?int $detectedInCalendarEventId,
    ) {
        $this->onQueue('heavy');
    }

    public function handle(IssueConflictDetector $detector): void
    {
        $event = $this->detectedInCalendarEventId !== null
            ? CalendarEvent::find($this->detectedInCalendarEventId)
            : null;

        if (empty($this->newIssueIds)) {
            $this->fireMeetingArtifactsReady($event);

            return;
        }

        try {
            $groups = $detector->detect($this->newIssueIds, $event);
        } catch (\Throwable $e) {
            Log::error('DetectIssueConflictsJob: failed', [
                'calendar_event_id' => $this->detectedInCalendarEventId,
                'error'             => $e->getMessage(),
            ]);
            throw $e; // re-throw so retry fires per CLAUDE.md rule 3
        }

        Log::info('DetectIssueConflictsJob: done', [
            'calendar_event_id' => $this->detectedInCalendarEventId,
            'groups'            => count($groups),
        ]);

        if (! empty($groups) && $event === null) {
            // Standalone (US-7.6): notify each author privately. Meeting conflicts
            // (US-7.5) are surfaced via the section in SendMeetingSummaryNotification.
            $groupUuids = array_map(static fn (array $g) => $g['group_uuid'], $groups);
            NotifyConflictsAuthorJob::dispatch($groupUuids);
        }

        $this->fireMeetingArtifactsReady($event);
    }

    /**
     * Last-chance: if all retries are exhausted, the summary must still go out.
     */
    public function failed(\Throwable $exception): void
    {
        $event = $this->detectedInCalendarEventId !== null
            ? CalendarEvent::find($this->detectedInCalendarEventId)
            : null;

        $this->fireMeetingArtifactsReady($event);
    }

    private function fireMeetingArtifactsReady(?CalendarEvent $event): void
    {
        if ($event === null) {
            return;
        }
        event(new MeetingArtifactsReady($event));
    }
}
