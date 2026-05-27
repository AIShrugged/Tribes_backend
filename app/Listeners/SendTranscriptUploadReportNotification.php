<?php

namespace App\Listeners;

use App\Events\MeetingArtifactsReady;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\IssueComment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * Send a personal Telegram report to the user who manually uploaded a transcript.
 *
 * Fires on MeetingArtifactsReady — the very last event in the post-transcript
 * pipeline (from DetectIssueConflictsJob). By this point summary, review,
 * followups, issues, and decisions all exist.
 *
 * Only triggers for manual uploads (platform = 'manual_upload'). Recall-flow
 * meetings already have their own team-channel notifications via
 * SendMeetingSummaryNotification.
 */
class SendTranscriptUploadReportNotification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 1;

    public function handle(MeetingArtifactsReady $event): void
    {
        $calendarEvent = $event->event;

        if ($calendarEvent->platform !== 'manual_upload') {
            return;
        }

        $uploader = $calendarEvent->creator;
        if (!$uploader) {
            return;
        }

        $telegramUserId = $uploader->telegramUser?->telegram_user_id;
        if (!$telegramUserId) {
            Log::info('SendTranscriptUploadReport: uploader has no Telegram linked', [
                'user_id' => $uploader->id,
                'calendar_event_id' => $calendarEvent->id,
            ]);
            return;
        }

        $text = $this->buildMessage($calendarEvent, $uploader);

        try {
            $telegram = new Api(config('telegram.bot_token'));
            $telegram->sendMessage([
                'chat_id'    => $telegramUserId,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ]);

            Log::info('SendTranscriptUploadReport: sent', [
                'user_id'           => $uploader->id,
                'calendar_event_id' => $calendarEvent->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SendTranscriptUploadReport: failed to send', [
                'user_id'           => $uploader->id,
                'calendar_event_id' => $calendarEvent->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    private function buildMessage(CalendarEvent $calendarEvent, User $uploader): string
    {
        $title = e($calendarEvent->title ?: 'Untitled meeting');
        $date = $calendarEvent->starts_at?->format('d.m.Y H:i') ?? '';

        $hasSummary  = $calendarEvent->meetingSummary()->exists();
        $hasReview   = $calendarEvent->meetingReview()->exists();
        $followups   = $calendarEvent->followups()->count();
        $newIssues   = Issue::where('sourceable_type', CalendarEvent::class)
            ->where('sourceable_id', $calendarEvent->id)
            ->whereNull('deleted_at')
            ->count();
        $updatedIssues = IssueComment::where('calendar_event_id', $calendarEvent->id)->count();

        $lines = [];
        $lines[] = "\xF0\x9F\x93\xA4 <b>Transcript upload report</b>";
        $lines[] = "<b>{$title}</b>" . ($date ? " ({$date})" : '');
        $lines[] = '';

        $lines[] = ($hasSummary ? "\xE2\x9C\x85" : "\xE2\x8F\xB3") . ' Summary: ' . ($hasSummary ? 'ready' : 'pending');
        $lines[] = ($hasReview  ? "\xE2\x9C\x85" : "\xE2\x8F\xB3") . ' Review: '  . ($hasReview  ? 'ready' : 'pending');

        if ($newIssues > 0 || $updatedIssues > 0) {
            $parts = [];
            if ($newIssues > 0) {
                $parts[] = "{$newIssues} new";
            }
            if ($updatedIssues > 0) {
                $parts[] = "{$updatedIssues} updated";
            }
            $lines[] = "\xF0\x9F\x93\x8B Tasks: " . implode(', ', $parts);
        } else {
            $lines[] = "\xF0\x9F\x93\x8B Tasks: none extracted";
        }

        if ($followups > 0) {
            $lines[] = "\xF0\x9F\x92\xAC Followup: sent";
        }

        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', ''));
        if ($frontendUrl) {
            $lines[] = '';
            $lines[] = "<a href=\"{$frontendUrl}/dashboard/meetings/{$calendarEvent->id}/overview\">View meeting</a>";
        }

        return implode("\n", $lines);
    }
}
