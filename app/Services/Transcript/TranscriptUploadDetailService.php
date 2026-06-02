<?php

namespace App\Services\Transcript;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueComment;

/**
 * Single source of truth for the "what did the post-transcript pipeline produce"
 * signals. Extracted from {@see \App\Listeners\SendTranscriptUploadReportNotification}
 * so the Upload Log detail endpoint and the Telegram report derive the same numbers.
 *
 * The pipeline status is not stored anywhere — it is derived live from the event's
 * artifacts (these signals settle asynchronously, ~1-2 min after the upload row is
 * marked 'done'). All queries are exists()/count() — no N+1.
 */
class TranscriptUploadDetailService
{
    /**
     * @return array{has_summary: bool, has_review: bool, followups_count: int, issues_created: int, issues_updated: int}
     */
    public function derive(CalendarEvent $event): array
    {
        return [
            'has_summary'     => $event->meetingSummary()->exists(),
            'has_review'      => $event->meetingReview()->exists(),
            'followups_count' => $event->followups()->count(),
            'issues_created'  => Issue::where('sourceable_type', CalendarEvent::class)
                ->where('sourceable_id', $event->id)
                ->whereNull('deleted_at')
                ->count(),
            'issues_updated'  => IssueComment::where('calendar_event_id', $event->id)->count(),
        ];
    }
}
