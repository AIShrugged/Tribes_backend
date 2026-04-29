<?php

namespace App\Services;

use App\Enums\AgendaStatus;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingAgenda;
use App\Models\MeetingSummary;
use App\Models\Team;
use App\Models\TeamNotificationSetting;
use App\Models\TelegramChatRegistration;
use App\Services\Meeting\MeetingContextService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class PreMeetingBriefService
{
    public function __construct(
        private readonly MeetingContextService $meetingContext,
    ) {}
    private const MAX_TASKS_SHOWN = 10;
    private const MAX_DESCRIPTION_LENGTH = 300;
    private const TELEGRAM_MAX_LENGTH = 4096;

    public function sendBriefs(): int
    {
        $from = Carbon::now()->addMinutes(10);
        $to = Carbon::now()->addMinutes(20);

        $events = CalendarEvent::query()
            ->whereBetween('starts_at', [$from, $to])
            ->with(['sources' => fn($q) => $q->withTrashed(), 'sources.user.teams', 'profiles.user'])
            ->get();

        $sent = 0;

        foreach ($events as $event) {
            $teams = $event->sources
                ->map(fn($source) => $source->user)
                ->filter()
                ->flatMap(fn($user) => $user->teams)
                ->unique('id');

            foreach ($teams as $team) {
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
            $agenda = MeetingAgenda::query()
                ->where('calendar_event_id', $event->id)
                ->whereNull('user_id')
                ->where('type', 'general')
                ->where('status', AgendaStatus::DONE->value)
                ->first();

            $text = $agenda
                ? $this->formatFromAgenda($agenda, $event, $team, $channelType)
                : $this->formatMessage($event, $team, $channelType);

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

        if ($overdue->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '🔴 <b>Overdue tasks:</b> ' . $overdue->count();
            $this->appendTaskLines($lines, $overdue, '• ', showDueDate: true);
        }

        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $allTasksUrl = $frontendUrl . '/dashboard/issues';
        $suffix = "\n\n<a href=\"" . e($allTasksUrl) . "\">show all</a>";

        $text = implode("\n", $lines);

        // Truncate if exceeds Telegram limit — cut at last newline to avoid breaking HTML tags
        if (mb_strlen($text) > self::TELEGRAM_MAX_LENGTH - mb_strlen($suffix)) {
            $cut = mb_substr($text, 0, self::TELEGRAM_MAX_LENGTH - mb_strlen($suffix));
            $lastNewline = mb_strrpos($cut, "\n");
            if ($lastNewline !== false) {
                $cut = mb_substr($cut, 0, $lastNewline);
            }
            $text = $cut;
        }

        return $text . $suffix;
    }

    private function formatFromAgenda(MeetingAgenda $agenda, CalendarEvent $event, Team $team, string $channelType): string
    {
        $raw = $agenda->raw_json ?? [];
        $lines = [];
        $lines[] = '📅 <b>Upcoming meeting in 15 minutes</b>';
        $lines[] = '';
        $lines[] = '<b>' . e($event->title) . '</b>';

        $start = Carbon::parse($event->starts_at);
        $end = $event->ends_at ? Carbon::parse($event->ends_at) : null;
        if ($end) {
            $duration = $start->diffInMinutes($end);
            $lines[] = '🕐 ' . $start->format('H:i') . ' — ' . $end->format('H:i') . ' (' . $duration . ' min)';
        } else {
            $lines[] = '🕐 ' . $start->format('H:i');
        }

        if ($event->url) {
            $lines[] = '🔗 <a href="' . e($event->url) . '">Join meeting</a>';
        }

        $attendees = $raw['attendees'] ?? [];
        if (!empty($attendees)) {
            $lines[] = '👥 ' . implode(', ', array_map('e', $attendees));
        }

        $attendeeEmails = $raw['attendee_emails'] ?? [];
        if (!empty($attendeeEmails)) {
            $team->loadMissing('users');
            $absent = $team->users
                ->filter(fn($u) => !in_array($u->email, $attendeeEmails))
                ->map(fn($u) => $u->name)
                ->filter()
                ->values();
            if ($absent->isNotEmpty()) {
                $lines[] = '⚠️ <b>Not attending:</b> ' . $absent->join(', ');
            }
        }

        // Agenda: meeting goal + discussion topics (from new agenda format)
        $meetingGoal      = $raw['meeting_goal'] ?? null;
        $discussionTopics = $raw['discussion_topics'] ?? [];
        $mainProblem      = $raw['main_problem'] ?? null;

        if ($meetingGoal || !empty($discussionTopics) || $mainProblem) {
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '📋 <b>Agenda</b>';

            if ($meetingGoal) {
                $lines[] = '';
                $lines[] = '<b>Цель:</b> ' . e($meetingGoal);
            }

            if (!empty($discussionTopics)) {
                $lines[] = '';
                foreach ($discussionTopics as $i => $topic) {
                    $title = is_array($topic) ? ($topic['title'] ?? '') : $topic;
                    $desc  = is_array($topic) ? ($topic['description'] ?? '') : '';
                    $lines[] = ($i + 1) . '. <b>' . e($title) . '</b>';
                    if ($desc) {
                        $lines[] = '   <i>' . e($desc) . '</i>';
                    }
                }
            }

            if ($mainProblem) {
                $lines[] = '';
                $lines[] = '⚠️ ' . e($mainProblem);
            }
        }

        $prevSummary = $raw['previous_summary'] ?? null;
        if ($prevSummary) {
            $daysAgo = $prevSummary['days_ago'] ?? 0;
            $daysLabel = $daysAgo === 1 ? '1 day ago' : $daysAgo . ' days ago';
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '📋 <b>Previous meeting</b> <i>(' . $daysLabel . ')</i>';

            // Show only key points (concise); avoid long summary text to prevent blockquote truncation
            $keyPoints = array_slice((array) ($prevSummary['key_points'] ?? []), 0, 5);
            if (!empty($keyPoints)) {
                $summaryLines = [];
                foreach ($keyPoints as $point) {
                    $summaryLines[] = '• ' . e($point);
                }
                $summaryBlock = implode("\n", $summaryLines);
                if ($channelType === 'telegram') {
                    $lines[] = '<blockquote>' . $summaryBlock . '</blockquote>';
                } else {
                    $lines[] = '';
                    $lines[] = $summaryBlock;
                }
            }
        }

        $completedTasks = $raw['completed_tasks'] ?? [];
        if (!empty($completedTasks)) {
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '✅ <b>Completed since last meeting:</b> ' . count($completedTasks);
            $this->appendTaskArrayLines($lines, $completedTasks, '  ✓ ');
        }

        $unresolvedDecisions = $raw['unresolved_decisions'] ?? [];
        if (!empty($unresolvedDecisions)) {
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '🔄 <b>Topics to revisit:</b>';
            foreach ($unresolvedDecisions as $decision) {
                $lines[] = '• ' . e($decision);
            }
        }

        // Tasks: fetch live from DB so they reflect current state
        $allOpenTasks = $this->findAllOpenTasks($event, $team->id);
        $now = Carbon::now();
        $overdue = $allOpenTasks->filter(fn($i) => $i->due_date && Carbon::parse($i->due_date)->lt($now));

        if ($overdue->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '🔴 <b>Overdue tasks:</b> ' . $overdue->count();
            $this->appendTaskLines($lines, $overdue, '• ', showDueDate: true);
        }

        // Fallback: old format topics_to_discuss
        $topicsToDiscuss = $raw['topics_to_discuss'] ?? [];
        if (empty($discussionTopics) && !empty($topicsToDiscuss)) {
            $lines[] = '';
            $lines[] = '━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '📝 <b>Topics to discuss:</b>';
            foreach ($topicsToDiscuss as $i => $topic) {
                $lines[] = ($i + 1) . '. ' . e($topic);
            }
        }

        $frontendUrl = rtrim(config('app.frontend_url'), '/');
        $allTasksUrl = $frontendUrl . '/dashboard/issues';
        $suffix = "\n\n<a href=\"" . e($allTasksUrl) . "\">show all</a>";

        $text = implode("\n", $lines);

        if (mb_strlen($text) > self::TELEGRAM_MAX_LENGTH - mb_strlen($suffix)) {
            $cut = mb_substr($text, 0, self::TELEGRAM_MAX_LENGTH - mb_strlen($suffix));
            $lastNewline = mb_strrpos($cut, "\n");
            if ($lastNewline !== false) {
                $cut = mb_substr($cut, 0, $lastNewline);
            }
            $text = $cut;
        }

        return $text . $suffix;
    }

    private function appendTaskArrayLines(array &$lines, array $tasks, string $prefix, bool $showDueDate = false): void
    {
        $shown = array_slice($tasks, 0, self::MAX_TASKS_SHOWN);
        $remaining = count($tasks) - count($shown);

        foreach ($shown as $task) {
            $line = $prefix . e($task['name'] ?? '');
            if ($showDueDate && !empty($task['due_date'])) {
                $line .= ' <i>(due ' . $task['due_date'] . ')</i>';
            }
            if (!empty($task['assignee'])) {
                $line .= ' — ' . e($task['assignee']);
            }
            $lines[] = $line;
        }

        if ($remaining > 0) {
            $lines[] = $prefix . '<i>…and ' . $remaining . ' more</i>';
        }
    }

    private function appendTaskLines(array &$lines, Collection $tasks, string $prefix, bool $showDueDate = false): void
    {
        $shown = $tasks->take(self::MAX_TASKS_SHOWN);
        $remaining = $tasks->count() - $shown->count();
        $frontendUrl = rtrim(config('app.frontend_url'), '/');

        foreach ($shown as $issue) {
            $assignee = $issue->assignee?->name ?? $issue->assignee_name;
            $issueUrl = $frontendUrl . '/dashboard/issues/' . $issue->id;
            $line = $prefix . '<a href="' . e($issueUrl) . '">' . e($issue->name) . '</a>';
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
            ->withoutTrashed()
            ->where('sourceable_type', CalendarEvent::class)
            ->where('sourceable_id', $previousEvent->id)
            ->where(fn($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->whereNotIn('status', ['done', 'cancelled'])
            ->count();

        $totalTasks = Issue::query()
            ->withoutTrashed()
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
        return $this->meetingContext->findPreviousEventWithSummary($event);
    }

    private function findAllOpenTasks(CalendarEvent $event, int $teamId): Collection
    {
        return $this->meetingContext->getCarriedTasks($event, $teamId);
    }

    private function findTasksCompletedBetween(CalendarEvent $currentEvent, CalendarEvent $previousEvent, int $teamId): Collection
    {
        return $this->meetingContext->getCompletedTasksBetween($currentEvent, $previousEvent, $teamId);
    }
}
