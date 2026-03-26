<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingSummary;
use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class PreMeetingBriefService
{
    private const MAX_TASKS_SHOWN = 10;
    private const MAX_DESCRIPTION_LENGTH = 300;
    private const TELEGRAM_MAX_LENGTH = 4096;

    public function sendBriefs(): int
    {
        $from = Carbon::now()->addMinutes(5);
        $to = Carbon::now()->addMinutes(15);

        $events = CalendarEvent::query()
            ->whereBetween('starts_at', [$from, $to])
            ->with(['source' => fn($q) => $q->withTrashed(), 'source.user.teams', 'profiles.user'])
            ->get();

        $sent = 0;

        foreach ($events as $event) {
            $user = $event->source?->user;
            if (! $user) {
                continue;
            }

            foreach ($user->teams as $team) {
                $settings = TeamNotificationSetting::query()
                    ->where('team_id', $team->id)
                    ->where('event_type', 'pre_meeting_brief')
                    ->where('enabled', true)
                    ->where('notifiable_type', TelegramChatRegistration::class)
                    ->with('notifiable')
                    ->get();

                foreach ($settings as $setting) {
                    $cacheKey = "pre_meeting_sent:{$event->id}:{$setting->id}";

                    if (Cache::has($cacheKey)) {
                        continue;
                    }

                    $registration = $setting->notifiable;
                    if (! $registration || ! $registration->telegram_chat_id) {
                        continue;
                    }

                    $this->send($registration, $event, $team, $setting->channel_type);
                    Cache::put($cacheKey, true, 1200);
                    $sent++;
                }
            }
        }

        return $sent;
    }

    private function send(TelegramChatRegistration $registration, CalendarEvent $event, Team $team, string $channelType): void
    {
        try {
            $text = $this->formatMessage($event, $team, $channelType);

            $telegram = new Api(config('telegram.bot_token'));
            $params = [
                'chat_id'    => $registration->telegram_chat_id,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ];

            if ($registration->message_thread_id) {
                $params['message_thread_id'] = $registration->message_thread_id;
            }

            $telegram->sendMessage($params);
        } catch (\Throwable $e) {
            Log::warning('PreMeetingBriefService: failed to send Telegram message', [
                'telegram_chat_id' => $registration->telegram_chat_id,
                'calendar_event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function formatMessage(CalendarEvent $event, Team $team, string $channelType = 'telegram'): string
    {
        $teamId = $team->id;
        $lines = [];
        $lines[] = '📅 <b>Upcoming meeting in 15 minutes</b>';
        $lines[] = '';
        $lines[] = '<b>' . e($event->title) . '</b>';

        // Duration
        $start = Carbon::parse($event->starts_at);
        $end = $event->ends_at ? Carbon::parse($event->ends_at) : null;
        if ($end) {
            $duration = $start->diffInMinutes($end);
            $lines[] = '🕐 ' . $start->format('H:i') . ' — ' . $end->format('H:i') . ' (' . $duration . ' min)';
        } else {
            $lines[] = '🕐 ' . $start->format('H:i');
        }

        // Meeting link
        if ($event->url) {
            $lines[] = '🔗 <a href="' . e($event->url) . '">Join meeting</a>';
        }

        // Attendees
        $attendees = $this->getAttendeeNames($event);
        if ($attendees->isNotEmpty()) {
            $lines[] = '👥 ' . $attendees->join(', ');
        }

        // Absent team members
        $absent = $this->getAbsentMembers($event, $team);
        if ($absent->isNotEmpty()) {
            $lines[] = '⚠️ <b>Not attending:</b> ' . $absent->join(', ');
        }

        // Agenda (truncated)
        if ($event->description) {
            $desc = strip_tags($event->description);
            if (mb_strlen($desc) > self::MAX_DESCRIPTION_LENGTH) {
                $desc = mb_substr($desc, 0, self::MAX_DESCRIPTION_LENGTH) . '…';
            }
            $lines[] = '';
            $lines[] = '<b>Agenda:</b>';
            $lines[] = e($desc);
        }

        // Previous meeting summary
        $previousEvent = $this->findPreviousEvent($event);
        $previousSummary = $previousEvent?->meetingSummary;
        if ($previousSummary) {
            $daysSince = (int) Carbon::parse($previousEvent->starts_at)->diffInDays(Carbon::parse($event->starts_at));
            $daysLabel = $daysSince === 1 ? '1 day ago' : $daysSince . ' days ago';
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '📋 <b>Previous meeting</b> <i>(' . $daysLabel . ')</i>';

            $summaryLines = [];
            $summaryLines[] = e($previousSummary->summary);

            if (! empty($previousSummary->key_points)) {
                $summaryLines[] = '';
                $summaryLines[] = '<b>Key points:</b>';
                foreach ($previousSummary->key_points as $point) {
                    $summaryLines[] = '• ' . e($point);
                }
            }

            // Decisions are shown separately in "Topics to revisit" — don't duplicate here

            $summaryBlock = implode("\n", $summaryLines);
            if ($channelType === 'telegram') {
                $lines[] = '<blockquote expandable>' . $summaryBlock . '</blockquote>';
            } else {
                $lines[] = '';
                $lines[] = $summaryBlock;
            }
        }

        // Tasks completed between meetings
        if ($previousEvent) {
            $completedTasks = $this->findTasksCompletedBetween($event, $previousEvent, $teamId);
            if ($completedTasks->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '━━━━━━━━━━━━━━━━━━━━';
                $lines[] = '✅ <b>Completed since last meeting:</b> ' . $completedTasks->count();
                $this->appendTaskLines($lines, $completedTasks, '  ✓ ');
            }
        }

        // Unresolved decisions from previous meeting
        if ($previousSummary && ! empty($previousSummary->decisions)) {
            $unresolvedDecisions = $this->findUnresolvedDecisions($previousSummary, $previousEvent, $teamId);
            if ($unresolvedDecisions->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '━━━━━━━━━━━━━━━━━━━━';
                $lines[] = '🔄 <b>Topics to revisit:</b>';
                foreach ($unresolvedDecisions as $decision) {
                    $lines[] = '• ' . e($decision);
                }
            }
        }

        // All open tasks (accumulated backlog), excluding cancelled
        $allOpenTasks = $this->findAllOpenTasks($event, $teamId);
        $overdue = $allOpenTasks->filter(fn($i) => $i->due_date && Carbon::parse($i->due_date)->lt(Carbon::now()));
        $notOverdue = $allOpenTasks->filter(fn($i) => ! $i->due_date || Carbon::parse($i->due_date)->gte(Carbon::now()));

        if ($overdue->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '🔴 <b>Overdue tasks:</b> ' . $overdue->count();
            $this->appendTaskLines($lines, $overdue, '• ', showDueDate: true);
        }

        if ($notOverdue->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '📌 <b>Open tasks:</b> ' . $notOverdue->count();
            $this->appendTaskLines($lines, $notOverdue, '• ', showDueDate: true);
        }

        $text = implode("\n", $lines);

        // Truncate if exceeds Telegram limit — cut at last newline to avoid breaking HTML tags
        if (mb_strlen($text) > self::TELEGRAM_MAX_LENGTH) {
            $cut = mb_substr($text, 0, self::TELEGRAM_MAX_LENGTH - 20);
            $lastNewline = mb_strrpos($cut, "\n");
            if ($lastNewline !== false) {
                $cut = mb_substr($cut, 0, $lastNewline);
            }
            $text = $cut . "\n\n<i>…truncated</i>";
        }

        return $text;
    }

    private function appendTaskLines(array &$lines, Collection $tasks, string $prefix, bool $showDueDate = false): void
    {
        $shown = $tasks->take(self::MAX_TASKS_SHOWN);
        $remaining = $tasks->count() - $shown->count();

        foreach ($shown as $issue) {
            $assignee = $issue->assignee?->name ?? $issue->assignee_name;
            $line = $prefix . e($issue->name);
            if ($showDueDate && $issue->due_date) {
                $line .= ' <i>(due ' . Carbon::parse($issue->due_date)->format('d.m') . ')</i>';
            }
            if ($assignee) {
                $line .= ' — ' . e($assignee);
            }
            $lines[] = $line;
        }

        if ($remaining > 0) {
            $lines[] = $prefix . '<i>…and ' . $remaining . ' more</i>';
        }
    }

    private function getAttendeeNames(CalendarEvent $event): Collection
    {
        return $event->profiles
            ->map(fn($p) => $p->user?->name)
            ->filter()
            ->values();
    }

    private function getAbsentMembers(CalendarEvent $event, Team $team): Collection
    {
        $team->loadMissing('users');
        $attendeeEmails = $event->profiles
            ->map(fn($p) => $p->user?->email)
            ->filter()
            ->values();

        // If no attendee emails resolved (profiles not linked to users), skip absent check
        if ($attendeeEmails->isEmpty()) {
            return collect();
        }

        return $team->users
            ->filter(fn($u) => ! $attendeeEmails->contains($u->email))
            ->map(fn($u) => $u->name)
            ->filter()
            ->values();
    }

    private function findUnresolvedDecisions(MeetingSummary $summary, CalendarEvent $previousEvent, int $teamId): Collection
    {
        $activeTasks = Issue::query()
            ->where('sourceable_type', CalendarEvent::class)
            ->where('sourceable_id', $previousEvent->id)
            ->where(fn($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->whereNotIn('status', ['done', 'cancelled'])
            ->count();

        $totalTasks = Issue::query()
            ->where('sourceable_type', CalendarEvent::class)
            ->where('sourceable_id', $previousEvent->id)
            ->where(fn($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->count();

        // All tasks resolved (done or cancelled) — nothing to revisit
        if ($totalTasks > 0 && $activeTasks === 0) {
            return collect();
        }

        // No tasks created OR some still open — show decisions as topics to revisit
        return collect($summary->decisions);
    }

    private function findPreviousEvent(CalendarEvent $event): ?CalendarEvent
    {
        return CalendarEvent::query()
            ->where('source_id', $event->source_id)
            ->where('starts_at', '<', $event->starts_at)
            ->whereHas('meetingSummary', fn($q) => $q->where('status', 'done'))
            ->orderByDesc('starts_at')
            ->with('meetingSummary')
            ->first();
    }

    private function findAllOpenTasks(CalendarEvent $event, int $teamId): Collection
    {
        $eventIds = CalendarEvent::query()
            ->where('source_id', $event->source_id)
            ->where('starts_at', '<', $event->starts_at)
            ->pluck('id');

        if ($eventIds->isEmpty()) {
            return collect();
        }

        return Issue::query()
            ->where('sourceable_type', CalendarEvent::class)
            ->whereIn('sourceable_id', $eventIds)
            ->where(fn($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->whereNotIn('status', ['done', 'cancelled'])
            ->with('assignee')
            ->get();
    }

    private function findTasksCompletedBetween(CalendarEvent $currentEvent, CalendarEvent $previousEvent, int $teamId): Collection
    {
        $eventIds = CalendarEvent::query()
            ->where('source_id', $currentEvent->source_id)
            ->where('starts_at', '<', $currentEvent->starts_at)
            ->pluck('id');

        if ($eventIds->isEmpty()) {
            return collect();
        }

        return Issue::query()
            ->where('sourceable_type', CalendarEvent::class)
            ->whereIn('sourceable_id', $eventIds)
            ->where(fn($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->where('status', 'done')
            ->where('updated_at', '>=', $previousEvent->starts_at)
            ->with('assignee')
            ->get();
    }
}
