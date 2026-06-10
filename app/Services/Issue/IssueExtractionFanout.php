<?php

namespace App\Services\Issue;

use App\Events\IssuesExtracted;
use App\Jobs\VerifyMeetingArtifactsJob;
use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The downstream that runs AFTER transcript issues are persisted.
 *
 * Shared single source of truth so the flag-off job (ExtractIssuesFromTranscriptJob) and the
 * gated approve replay (ApproveExtractionPlanService) dispatch the identical set/order — no drift.
 * Takes the ALREADY-persisted issue collection; the write happens before this is called.
 *
 * @see \App\Jobs\ExtractIssuesFromTranscriptJob
 */
class IssueExtractionFanout
{
    public function afterIssues(Collection $issues, CalendarEvent $event, Team $team, User $user): void
    {
        if ($issues->isNotEmpty()) {
            IssuesExtracted::dispatch($issues, $team, $user);
        }

        VerifyMeetingArtifactsJob::dispatch($event, $team, $user);
    }
}
