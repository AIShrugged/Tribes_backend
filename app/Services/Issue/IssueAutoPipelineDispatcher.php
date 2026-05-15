<?php

namespace App\Services\Issue;

use App\Events\MeetingArtifactsReady;
use App\Jobs\DetectIssueConflictsJob;
use App\Jobs\ValidateAutoIssuesContentJob;
use App\Models\CalendarEvent;
use App\Models\Issue;
use Illuminate\Support\Facades\Log;

class IssueAutoPipelineDispatcher
{
    public function dispatchForMeeting(CalendarEvent $event, array $issueIds): void
    {
        $eligible = $this->filterEligible($issueIds);

        if (empty($eligible)) {
            // No detection needed, but the summary listener still has to fire for this meeting.
            event(new MeetingArtifactsReady($event));

            return;
        }

        ValidateAutoIssuesContentJob::dispatch($eligible);
        // DetectIssueConflictsJob fires MeetingArtifactsReady at the end of its handle()
        // (and in failed()) — this guarantees the summary section renders with conflicts.
        DetectIssueConflictsJob::dispatch($eligible, $event->id);

        Log::info('IssueAutoPipelineDispatcher: dispatchForMeeting', [
            'calendar_event_id' => $event->id,
            'issue_count'       => count($eligible),
        ]);
    }

    public function dispatchForStandalone(array $issueIds): void
    {
        $eligible = $this->filterEligible($issueIds);

        if (empty($eligible)) {
            return;
        }

        ValidateAutoIssuesContentJob::dispatch($eligible);
        DetectIssueConflictsJob::dispatch($eligible, null);

        Log::info('IssueAutoPipelineDispatcher: dispatchForStandalone', [
            'issue_count' => count($eligible),
        ]);
    }

    /**
     * Filter out issues whose author is a demo user — they must not produce live notifications
     * or LLM calls during demo seeding. Preserves the input order so callers and tests get
     * deterministic IDs.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    private function filterEligible(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $eligibleSet = Issue::query()
            ->whereIn('id', $ids)
            ->whereDoesntHave('user', fn ($q) => $q->where('is_demo', true))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        return array_values(array_filter(
            array_map('intval', $ids),
            static fn (int $id) => isset($eligibleSet[$id]),
        ));
    }
}
