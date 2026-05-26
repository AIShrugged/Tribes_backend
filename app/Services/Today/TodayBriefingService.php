<?php

namespace App\Services\Today;

use App\Domain\DTO\Today\TodayBriefingDTO;
use App\Domain\DTO\Today\TodayCarriedTaskDTO;
use App\Domain\DTO\Today\TodayDeadlineTaskDTO;
use App\Domain\DTO\Today\TodayEventDTO;
use App\Domain\DTO\Today\TodayMeetingReviewDTO;
use App\Domain\DTO\Today\TodayMeetingSummaryDTO;
use App\Domain\DTO\Today\TodayMeetingTaskDTO;
use App\Domain\DTO\Today\TodayStaleTaskDTO;
use App\Domain\DTO\Today\TodayTaskGroupsDTO;
use App\Domain\DTO\Today\TodayWaitingTaskDTO;
use App\Enums\AgendaStatus;
use App\Models\AgendaTemplate;
use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\MeetingAgenda;
use App\Services\Agenda\AgendaRenderer;
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
        private readonly TaskDeadlineGrouper $taskGrouper,
    ) {}

    public function getBriefing(User $user, Carbon $date, ?int $organizationId = null): TodayBriefingDTO
    {
        $taskGroups = $this->buildTaskGroups($user, $organizationId);

        $hasCalendar = Source::query()
            ->where('user_id', $user->id)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->withTrashed()
            ->exists();

        if (! $hasCalendar) {
            return $this->emptyBriefing($date, 'empty', $taskGroups);
        }

        $events = $this->loadEvents($user, $date, $organizationId);

        if ($events->isEmpty()) {
            // Calendar connected but no events today — still show waiting/stale tasks
            return new TodayBriefingDTO(
                state: 'active',
                date: $date->format('Y-m-d'),
                events: [],
                carried_tasks: [],
                waiting_on_you: $this->buildWaitingOnYou($user, $organizationId),
                stale: $this->buildStaleForUser($user, $organizationId),
                nudge: $this->nudgeService->getCached($user->id, $date),
                task_groups: $taskGroups,
            );
        }

        $hasReadyMeeting = $events->contains(
            fn(CalendarEvent $e) => $e->meetingSummary && $e->meetingSummary->status === 'done'
        );
        $state = $hasReadyMeeting ? 'active' : 'waiting';

        $eventDTOs = $events
            ->map(fn(CalendarEvent $e) => $this->buildEventDTO($e, $user, $organizationId))
            ->values()
            ->all();

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

        $waitingDTOs = $this->buildWaitingOnYou($user, $organizationId);

        $nudge = $this->nudgeService->getCached($user->id, $date);

        return new TodayBriefingDTO(
            state: $state,
            date: $date->format('Y-m-d'),
            events: $eventDTOs,
            carried_tasks: $carriedDTOs,
            waiting_on_you: $waitingDTOs,
            stale: $staleDTOs,
            nudge: $nudge,
            task_groups: $taskGroups,
        );
    }

    private function buildTaskGroups(User $user, ?int $organizationId = null): TodayTaskGroupsDTO
    {
        $today = Carbon::today();
        $groups = $this->taskGrouper->groupForUser($user, $organizationId);

        $toDTO = function (Issue $issue) use ($today): TodayDeadlineTaskDTO {
            $due = $issue->due_date ? Carbon::parse($issue->due_date) : null;
            $daysOverdue = $due && $due->lt($today)
                ? (int) $due->diffInDays($today)
                : null;

            return new TodayDeadlineTaskDTO(
                id: $issue->id,
                name: $issue->name,
                status: $issue->status,
                priority: (int) ($issue->priority ?? 0),
                due_date: $due?->format('Y-m-d'),
                days_overdue: $daysOverdue,
                assignee_name: $issue->assignee?->name ?? $issue->assignee_name,
            );
        };

        return new TodayTaskGroupsDTO(
            focused: ($groups['focused'] ?? collect())->map($toDTO)->all(),
            today:   $groups['today']->map($toDTO)->all(),
            current: $groups['current']->map($toDTO)->all(),
            overdue: $groups['overdue']->map($toDTO)->all(),
        );
    }

    private function loadEvents(User $user, Carbon $date, ?int $organizationId = null): Collection
    {
        $startOfDay = $date->copy()->startOfDay()->utc();
        $endOfDay = $date->copy()->endOfDay()->utc();
        $sourceIds = Source::query()
            ->where('user_id', $user->id)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->pluck('id');

        return CalendarEvent::query()
            ->where(function ($q) use ($user, $sourceIds, $organizationId) {
                $q->whereHas('sources', function ($sq) use ($user, $organizationId) {
                    $sq->where('user_id', $user->id)
                        ->when($organizationId !== null, fn ($sourceQuery) => $sourceQuery->where('organization_id', $organizationId));
                });
                if ($sourceIds->isNotEmpty()) {
                    $q->orWhereIn('source_id', $sourceIds);
                }
                if ($organizationId === null) {
                    $q->orWhereHas('profiles', fn($pq) => $pq->where('user_id', $user->id));
                }
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

    private function buildEventDTO(CalendarEvent $event, User $user, ?int $organizationId = null): TodayEventDTO
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
                ->forMeeting($event->id)
                ->when($organizationId !== null, fn ($q) => $q->inOrganization($organizationId))
                ->whereNotIn('status', ['cancelled'])
                ->with('assignee')
                ->get();

            $totalTasks = $allTasks->count();
            $doneTasks = $allTasks->where('status', 'done')->count();
            $prevTasks = $allTasks;
        } else {
            $prevEvent = $this->meetingContext->findPreviousEventWithTasks($event);

            $prevTasks = collect();
            $totalTasks = 0;
            $doneTasks = 0;

            if ($prevEvent) {
                $allPrevTasks = Issue::withoutTrashed()
                    ->forMeeting($prevEvent->id)
                    ->when($organizationId !== null, fn ($q) => $q->inOrganization($organizationId))
                    ->whereNotIn('status', ['cancelled'])
                    ->with('assignee')
                    ->get();

                $totalTasks = $allPrevTasks->count();
                $doneTasks = $allPrevTasks->where('status', 'done')->count();
                $prevTasks = $allPrevTasks;
            }
        }

        // Agenda content: only for future/ready meetings — not for past meetings without a briefing
        $agendaContent = $meetingState !== 'waiting' ? $this->loadAgendaContent($event, $user) : null;

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
            attendees: $summary->calendarEvent->participants->map(fn ($p) => ['name' => $p->name])->values()->all(),
            repeated_discussions: $summary->repeated_discussions ?? [],
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

        if ($general) {
            if ($general->isGeneral() && ! empty($general->raw_json)) {
                $template = $this->resolveTemplate($user);
                return app(AgendaRenderer::class)->renderForWeb($general->raw_json, $event, $template);
            }
            return $general->content;
        }

        // 2. Try personal upcoming agenda for this meeting's series.
        //    Upcoming agenda is "preparation for next meeting" generated from a previous meeting
        //    in the same series — matched by series_key (URL or title).
        $upcoming = UpcomingAgenda::query()
            ->where('user_id', $user->id)
            ->where('series_key', $event->seriesKey())
            ->where('status', 'done')
            ->orderByDesc('updated_at')
            ->first();

        if ($upcoming && $upcoming->content) {
            return $upcoming->content;
        }

        return null;
    }

    private function resolveTemplate(User $user): ?AgendaTemplate
    {
        $teamId = $user->teams()->first()?->id;
        if (! $teamId) {
            return null;
        }

        return AgendaTemplate::where('team_id', $teamId)->first();
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

    private function buildWaitingOnYou(User $user, ?int $organizationId = null): array
    {
        $issues = Issue::query()
            ->withoutTrashed()
            ->where('assignee_id', $user->id)
            ->when($organizationId !== null, fn ($q) => $q->inOrganization($organizationId))
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

    private function buildStaleForUser(User $user, ?int $organizationId = null): array
    {
        $sourceIds = Source::query()
            ->where('user_id', $user->id)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->pluck('id');

        // Support both pivot-based (calendar_event_source) and direct source_id linkage
        $userEventIds = CalendarEvent::query()
            ->where(function ($q) use ($user, $sourceIds, $organizationId) {
                $q->whereHas('sources', function ($sq) use ($user, $organizationId) {
                    $sq->where('user_id', $user->id)
                        ->when($organizationId !== null, fn ($sourceQuery) => $sourceQuery->where('organization_id', $organizationId));
                });
                if ($sourceIds->isNotEmpty()) {
                    $q->orWhereIn('source_id', $sourceIds);
                }
                if ($organizationId === null) {
                    $q->orWhereHas('profiles', fn($pq) => $pq->where('user_id', $user->id));
                }
            })
            ->pluck('id');

        if ($userEventIds->isEmpty()) {
            return [];
        }

        $openIssues = Issue::query()
            ->withoutTrashed()
            ->where('sourceable_type', CalendarEvent::class)
            ->whereIn('sourceable_id', $userEventIds)
            ->when($organizationId !== null, fn ($q) => $q->inOrganization($organizationId))
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

    private function emptyBriefing(Carbon $date, string $state, ?TodayTaskGroupsDTO $taskGroups = null): TodayBriefingDTO
    {
        return new TodayBriefingDTO(
            state: $state,
            date: $date->format('Y-m-d'),
            events: [],
            carried_tasks: [],
            waiting_on_you: [],
            stale: [],
            nudge: null,
            task_groups: $taskGroups ?? TodayTaskGroupsDTO::empty(),
        );
    }
}
