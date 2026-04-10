<?php

namespace App\Services\Today;

use App\Domain\DTO\Today\TodayBriefingDTO;
use App\Domain\DTO\Today\TodayCarriedTaskDTO;
use App\Domain\DTO\Today\TodayEventDTO;
use App\Domain\DTO\Today\TodayMeetingReviewDTO;
use App\Domain\DTO\Today\TodayMeetingSummaryDTO;
use App\Domain\DTO\Today\TodayMeetingTaskDTO;
use App\Domain\DTO\Today\TodayStaleTaskDTO;
use App\Domain\DTO\Today\TodayWaitingTaskDTO;
use App\Enums\AgendaStatus;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingAgenda;
use App\Models\MeetingReview;
use App\Models\MeetingSummary;
use App\Models\Source;
use App\Models\UpcomingAgenda;
use App\Models\User;
use App\Services\Meeting\MeetingContextService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TodayBriefingService
{
    public function __construct(
        private readonly MeetingContextService $meetingContext,
        private readonly DailyNudgeService $nudgeService,
    ) {}

    public function getBriefing(User $user, Carbon $date): TodayBriefingDTO
    {
        $hasCalendar = Source::query()->where('user_id', $user->id)->withTrashed()->exists();

        if (! $hasCalendar) {
            return $this->emptyBriefing($date, 'empty');
        }

        $events = $this->loadEvents($user, $date);

        if ($events->isEmpty()) {
            // Calendar connected but no events today — still show waiting/stale tasks
            return new TodayBriefingDTO(
                state: 'active',
                date: $date->format('Y-m-d'),
                events: [],
                carried_tasks: [],
                waiting_on_you: $this->buildWaitingOnYou($user),
                stale: $this->buildStaleForUser($user),
                nudge: $this->nudgeService->getCached($user->id, $date),
            );
        }

        $hasReadyMeeting = $events->contains(
            fn(CalendarEvent $e) => $e->meetingSummary && $e->meetingSummary->status === 'done'
        );
        $state = $hasReadyMeeting ? 'active' : 'waiting';

        $eventDTOs = $events->map(fn(CalendarEvent $e) => $this->buildEventDTO($e, $user))->values()->all();

        // Collect all carried tasks across all meetings
        $allCarried = collect();
        foreach ($events as $event) {
            $carried = $this->meetingContext->getCarriedTasks($event);
            $allCarried = $allCarried->merge($carried);
        }
        $allCarried = $allCarried->unique('id');

        // Batch count syncs for efficiency
        $syncsCounts = $this->meetingContext->batchCountSyncsSinceCreated($allCarried);

        $carriedDTOs = $allCarried->map(fn(Issue $issue) => $this->buildCarriedTaskDTO($issue, $syncsCounts))
            ->sortByDesc('syncs_since_created')
            ->values()
            ->all();

        // Stale = carried with syncs_since_created >= 2, converted to StaleTask DTOs
        $staleDTOs = collect($carriedDTOs)
            ->filter(fn(TodayCarriedTaskDTO $t) => $t->syncs_since_created >= 2)
            ->map(fn(TodayCarriedTaskDTO $t) => new TodayStaleTaskDTO(
                id: $t->id,
                name: $t->name,
                assignee_name: $t->assignee_name,
                description: null,
                syncs_since_created: $t->syncs_since_created,
            ))
            ->values()
            ->all();

        $waitingDTOs = $this->buildWaitingOnYou($user);

        $nudge = $this->nudgeService->getCached($user->id, $date);

        return new TodayBriefingDTO(
            state: $state,
            date: $date->format('Y-m-d'),
            events: $eventDTOs,
            carried_tasks: $carriedDTOs,
            waiting_on_you: $waitingDTOs,
            stale: $staleDTOs,
            nudge: $nudge,
        );
    }

    private function loadEvents(User $user, Carbon $date): Collection
    {
        $startOfDay = $date->copy()->startOfDay()->utc();
        $endOfDay = $date->copy()->endOfDay()->utc();
        $sourceIds = Source::query()->where('user_id', $user->id)->pluck('id');

        return CalendarEvent::query()
            ->where(function ($q) use ($user, $sourceIds) {
                $q->whereHas('sources', fn($sq) => $sq->where('user_id', $user->id));
                if ($sourceIds->isNotEmpty()) {
                    $q->orWhereIn('source_id', $sourceIds);
                }
                $q->orWhereHas('profiles', fn($pq) => $pq->where('user_id', $user->id));
            })
            ->whereBetween('starts_at', [$startOfDay, $endOfDay])
            ->with([
                'meetingSummary',
                'meetingReview',
                'participants',
            ])
            ->orderBy('starts_at')
            ->get();
    }

    private function buildEventDTO(CalendarEvent $event, User $user): TodayEventDTO
    {
        $summary = $event->meetingSummary;
        $review = $event->meetingReview;

        $meetingState = 'scheduled';
        if ($summary && $summary->status === 'done') {
            $meetingState = 'ready';
        } elseif ($event->ends_at && Carbon::parse($event->ends_at)->isPast()) {
            $meetingState = 'waiting';
        }

        // Ready meeting: show tasks created on this meeting
        // Future/waiting meeting: show open tasks from previous meeting in series
        if ($meetingState === 'ready') {
            $allTasks = Issue::withoutTrashed()
                ->where('sourceable_type', CalendarEvent::class)
                ->where('sourceable_id', $event->id)
                ->whereNotIn('status', ['cancelled'])
                ->with('assignee')
                ->get();

            $totalTasks = $allTasks->count();
            $doneTasks = $allTasks->where('status', 'done')->count();
            $prevTasks = $allTasks->whereNotIn('status', ['done']);
        } else {
            $prevEvent = $this->meetingContext->findPreviousEventWithTasks($event);

            $prevTasks = collect();
            $totalTasks = 0;
            $doneTasks = 0;

            if ($prevEvent) {
                $allPrevTasks = Issue::withoutTrashed()
                    ->where('sourceable_type', CalendarEvent::class)
                    ->where('sourceable_id', $prevEvent->id)
                    ->whereNotIn('status', ['cancelled'])
                    ->with('assignee')
                    ->get();

                $totalTasks = $allPrevTasks->count();
                $doneTasks = $allPrevTasks->where('status', 'done')->count();
                $prevTasks = $allPrevTasks->whereNotIn('status', ['done']);
            }
        }

        // Agenda content: personal upcoming agenda for user, or general meeting agenda
        $agendaContent = $this->loadAgendaContent($event, $user);

        return new TodayEventDTO(
            id: $event->id,
            title: $event->title ?? '',
            starts_at: $event->starts_at->toIso8601String(),
            ends_at: $event->ends_at?->toIso8601String() ?? $event->starts_at->addHour()->toIso8601String(),
            participants_count: $event->participants->count(),
            platform: $event->platform,
            meeting_url: $event->url,
            meeting_state: $meetingState,
            summary: $this->buildSummaryDTO($summary),
            review: $this->buildReviewDTO($review),
            tasks: $prevTasks->map(fn(Issue $i) => $this->buildMeetingTaskDTO($i))->values()->all(),
            total_tasks_count: $totalTasks,
            done_tasks_count: $doneTasks,
            agenda_content: $agendaContent,
        );
    }

    private function buildSummaryDTO(?MeetingSummary $summary): ?TodayMeetingSummaryDTO
    {
        if (! $summary || $summary->status !== 'done') {
            return null;
        }

        return new TodayMeetingSummaryDTO(
            title: $summary->title ?? '',
            summary: $summary->summary ?? '',
            key_points: $summary->key_points ?? [],
            decisions: $summary->decisions ?? [],
        );
    }

    private function buildReviewDTO(?MeetingReview $review): ?TodayMeetingReviewDTO
    {
        if (! $review || $review->status !== 'done') {
            return null;
        }

        return new TodayMeetingReviewDTO(
            key_insight: $review->key_insight,
            suggestions: $review->suggestions ?? [],
        );
    }

    private function loadAgendaContent(CalendarEvent $event, User $user): ?string
    {
        // 1. Try general meeting agenda (directly linked to this event)
        $general = MeetingAgenda::query()
            ->where('calendar_event_id', $event->id)
            ->where('type', 'general')
            ->where('status', AgendaStatus::DONE)
            ->first();

        if ($general && $general->content) {
            return $general->content;
        }

        // 2. Try personal upcoming agenda — the latest one for this user.
        //    Upcoming agenda is "preparation for next meeting" generated from a previous meeting.
        //    Show it on the next unprocessed meeting of the day.
        $upcoming = UpcomingAgenda::query()
            ->where('user_id', $user->id)
            ->where('status', 'done')
            ->orderByDesc('created_at')
            ->first();

        if ($upcoming && $upcoming->content) {
            return $upcoming->content;
        }

        return null;
    }

    private function buildMeetingTaskDTO(Issue $issue): TodayMeetingTaskDTO
    {
        $isOverdue = $issue->due_date && Carbon::parse($issue->due_date)->lt(Carbon::today());

        return new TodayMeetingTaskDTO(
            id: $issue->id,
            name: $issue->name,
            description: $issue->description,
            status: $issue->status,
            assignee_name: $issue->assignee?->name ?? $issue->assignee_name,
            assignee_id: $issue->assignee_id,
            due_date: $issue->due_date?->format('Y-m-d'),
            is_overdue: $isOverdue,
        );
    }

    private function buildCarriedTaskDTO(Issue $issue, array $syncsCounts): TodayCarriedTaskDTO
    {
        $sourceEvent = $issue->sourceable;

        return new TodayCarriedTaskDTO(
            id: $issue->id,
            name: $issue->name,
            status: $issue->status,
            assignee_id: $issue->assignee_id,
            assignee_name: $issue->assignee?->name ?? $issue->assignee_name,
            source_meeting_title: $sourceEvent instanceof CalendarEvent ? ($sourceEvent->title ?? '') : '',
            source_meeting_date: $sourceEvent instanceof CalendarEvent ? $sourceEvent->starts_at->format('Y-m-d') : '',
            syncs_since_created: $syncsCounts[$issue->id] ?? 0,
        );
    }

    private function buildWaitingOnYou(User $user): array
    {
        $issues = Issue::query()
            ->withoutTrashed()
            ->where('assignee_id', $user->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->with('sourceable')
            ->orderByDesc('registration_date')
            ->limit(20)
            ->get();

        return $issues->map(function (Issue $issue) {
            $sourceEvent = $issue->sourceable;
            $ageDays = $issue->registration_date
                ? (int) Carbon::parse($issue->registration_date)->diffInDays(Carbon::today())
                : (int) $issue->created_at->diffInDays(Carbon::today());

            return new TodayWaitingTaskDTO(
                id: $issue->id,
                name: $issue->name,
                description: $issue->description,
                age_days: $ageDays,
                source_meeting_title: $sourceEvent instanceof CalendarEvent ? ($sourceEvent->title ?? null) : null,
            );
        })->values()->all();
    }

    private function buildStaleForUser(User $user): array
    {
        $sourceIds = Source::query()->where('user_id', $user->id)->pluck('id');

        // Support both pivot-based (calendar_event_source) and direct source_id linkage
        $userEventIds = CalendarEvent::query()
            ->where(function ($q) use ($user, $sourceIds) {
                $q->whereHas('sources', fn($sq) => $sq->where('user_id', $user->id));
                if ($sourceIds->isNotEmpty()) {
                    $q->orWhereIn('source_id', $sourceIds);
                }
                $q->orWhereHas('profiles', fn($pq) => $pq->where('user_id', $user->id));
            })
            ->pluck('id');

        if ($userEventIds->isEmpty()) {
            return [];
        }

        $openIssues = Issue::query()
            ->withoutTrashed()
            ->where('sourceable_type', CalendarEvent::class)
            ->whereIn('sourceable_id', $userEventIds)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->with(['assignee', 'sourceable'])
            ->limit(30)
            ->get();

        if ($openIssues->isEmpty()) {
            return [];
        }

        $syncsCounts = $this->meetingContext->batchCountSyncsSinceCreated($openIssues);

        return $openIssues
            ->filter(fn(Issue $i) => ($syncsCounts[$i->id] ?? 0) >= 2)
            ->map(fn(Issue $i) => new TodayStaleTaskDTO(
                id: $i->id,
                name: $i->name,
                assignee_name: $i->assignee?->name ?? $i->assignee_name,
                description: $i->description,
                syncs_since_created: $syncsCounts[$i->id] ?? 0,
            ))
            ->sortByDesc('syncs_since_created')
            ->values()
            ->all();
    }

    private function emptyBriefing(Carbon $date, string $state): TodayBriefingDTO
    {
        return new TodayBriefingDTO(
            state: $state,
            date: $date->format('Y-m-d'),
            events: [],
            carried_tasks: [],
            waiting_on_you: [],
            stale: [],
            nudge: null,
        );
    }
}
