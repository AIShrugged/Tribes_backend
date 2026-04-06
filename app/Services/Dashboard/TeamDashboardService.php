<?php

namespace App\Services\Dashboard;

use App\Models\CalendarEvent;
use App\Models\Issue;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TeamDashboardService
{
    public function build(Team $team, User $viewer): array
    {
        $team->loadMissing('users');

        $meetings = $this->teamMeetings($team);
        $issues = $this->teamIssues($team);
        $now = now();
        $upcomingMeeting = $meetings->filter(fn (CalendarEvent $event) => $event->starts_at?->gte($now))->sortBy('starts_at')->first();
        $latestMeeting = $meetings->sortByDesc('starts_at')->first();
        $pastMeetings = $meetings->filter(fn (CalendarEvent $event) => $event->starts_at?->lt($now))->values();
        $pastMeetingsWithSummary = $pastMeetings->filter(fn (CalendarEvent $event) => $event->meetingSummary?->status === 'done')->values();
        $sinceDate = $now->copy()->subWeek()->startOfDay();

        return [
            'team' => $this->teamPayload($team),
            'kpis' => [
                'action_items' => $this->actionItemsKpi($issues),
                'meetings' => $this->meetingKpi($meetings),
                'people' => $this->peopleKpi($team),
            ],
            'tabs' => [
                'status' => $this->statusTab($issues, $meetings, $sinceDate),
                'meeting_readiness' => $this->meetingReadinessTab($team, $upcomingMeeting ?? $latestMeeting, $meetings),
                'people' => $this->peopleTab($team, $issues, $meetings),
                'health' => $this->healthTab($issues, $meetings, $pastMeetingsWithSummary),
                'risks' => $this->risksTab($issues, $meetings),
            ],
            'sections' => [
                'since_last_week' => $this->sinceLastWeekSections($issues, $meetings, $sinceDate),
                'deadlines_and_priorities' => $this->deadlinesAndPriorities($issues),
                'decisions_needed' => $this->decisionsNeeded($pastMeetingsWithSummary),
            ],
            'upcoming_meeting' => $upcomingMeeting
                ? $this->meetingCard($upcomingMeeting)
                : null,
            'latest_meeting' => $latestMeeting
                ? $this->meetingCard($latestMeeting)
                : null,
            'viewer' => [
                'id' => $viewer->id,
                'name' => $viewer->name,
            ],
        ];
    }

    private function teamPayload(Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'slug' => $team->slug,
            'employee_count' => $team->employee_count,
            'members' => $team->users
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
        ];
    }

    private function actionItemsKpi(Collection $issues): array
    {
        $distinctMeetings = $issues
            ->filter(fn (Issue $issue) => $issue->sourceable_type === CalendarEvent::class && $issue->sourceable_id)
            ->pluck('sourceable_id')
            ->unique()
            ->count();

        return [
            'total' => $issues->count(),
            'across_meetings' => $distinctMeetings,
            'done' => $issues->where('status', 'done')->count(),
            'in_progress' => $issues->whereIn('status', ['open', 'in_progress', 'paused', 'review', 'reopen'])->count(),
            'overdue' => $issues->filter(fn (Issue $issue) => $this->isOverdue($issue))->count(),
        ];
    }

    private function meetingKpi(Collection $meetings): array
    {
        $withSummary = $meetings->filter(fn (CalendarEvent $event) => $event->meetingSummary?->status === 'done');
        $withReview = $meetings->filter(fn (CalendarEvent $event) => $event->meetingReview?->status === 'done');

        return [
            'total' => $meetings->count(),
            'with_summary' => $withSummary->count(),
            'with_review' => $withReview->count(),
            'completion_rate' => $meetings->isNotEmpty() ? round(($withSummary->count() / $meetings->count()) * 100, 1) : 0.0,
        ];
    }

    private function peopleKpi(Team $team): array
    {
        return [
            'total' => $team->users->count(),
        ];
    }

    private function statusTab(Collection $issues, Collection $meetings, Carbon $sinceDate): array
    {
        $sections = [
            'completed' => $issues->filter(fn (Issue $issue) => $issue->status === 'done'),
            'in_progress' => $issues->filter(fn (Issue $issue) => in_array($issue->status, ['open', 'in_progress', 'paused', 'review', 'reopen'], true) && ! $this->isOverdue($issue)),
            'overdue' => $issues->filter(fn (Issue $issue) => $this->isOverdue($issue)),
            'new' => $issues->filter(fn (Issue $issue) => $issue->status === 'open' && $issue->created_at?->gte($sinceDate)),
        ];

        return [
            'sections' => collect($sections)->map(function (Collection $sectionItems, string $key) {
                return [
                    'key' => $key,
                    'label' => Str::headline(str_replace('_', ' ', $key)),
                    'tone' => match ($key) {
                        'completed' => 'success',
                        'overdue' => 'danger',
                        'new' => 'warning',
                        default => 'neutral',
                    },
                    'count' => $sectionItems->count(),
                    'items' => $sectionItems->sortByDesc('updated_at')->take(6)->map(fn (Issue $issue) => $this->issueCard($issue))->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    private function meetingReadinessTab(Team $team, ?CalendarEvent $meeting, Collection $meetings): array
    {
        if (! $meeting) {
            return [
                'status' => 'none',
                'meeting' => null,
                'checks' => [],
                'notes' => ['No upcoming meeting found for this team.'],
            ];
        }

        $checks = [
            'agenda_ready' => (bool) ($meeting->description || $meeting->agendas->isNotEmpty()),
            'summary_ready' => $meeting->meetingSummary?->status === 'done',
            'review_ready' => $meeting->meetingReview?->status === 'done',
            'participants_confirmed' => $meeting->participants->isNotEmpty(),
            'bot_required' => (bool) $meeting->isRequiredBot(),
        ];

        $readyScore = collect($checks)->filter()->count();
        $status = $readyScore >= 4 ? 'ready' : 'attention';

        return [
            'status' => $status,
            'score' => $readyScore,
            'meeting' => $this->meetingCard($meeting),
            'checks' => collect($checks)->map(fn (bool $value, string $key) => [
                'key' => $key,
                'label' => Str::headline(str_replace('_', ' ', $key)),
                'value' => $value,
            ])->values()->all(),
            'notes' => $this->meetingReadinessNotes($meeting, $team),
        ];
    }

    private function peopleTab(Team $team, Collection $issues, Collection $meetings): array
    {
        $latestMeetingByUser = fn (User $user) => $meetings->filter(function (CalendarEvent $event) use ($user) {
            return $event->sources->contains(fn ($source) => (int) $source->user_id === $user->id)
                || $event->participants->contains(fn ($participant) => (int) $participant->profile?->user_id === $user->id);
        })->sortByDesc('starts_at')->first();

        return [
            'members' => $team->users->map(function (User $user) use ($issues, $latestMeetingByUser) {
                $memberIssues = $issues->filter(fn (Issue $issue) => (int) $issue->assignee_id === $user->id);
                $latestMeeting = $latestMeetingByUser($user);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'open_tasks' => $memberIssues->whereIn('status', ['open', 'in_progress', 'paused', 'review', 'reopen'])->count(),
                    'done_tasks' => $memberIssues->where('status', 'done')->count(),
                    'overdue_tasks' => $memberIssues->filter(fn (Issue $issue) => $this->isOverdue($issue))->count(),
                    'latest_meeting' => $latestMeeting ? [
                        'id' => $latestMeeting->id,
                        'title' => $latestMeeting->title,
                        'starts_at' => $latestMeeting->starts_at,
                    ] : null,
                ];
            })->values()->all(),
        ];
    }

    private function healthTab(Collection $issues, Collection $meetings, Collection $pastMeetingsWithSummary): array
    {
        $meetingCoverage = $meetings->isNotEmpty()
            ? round(($pastMeetingsWithSummary->count() / max($meetings->count(), 1)) * 100, 1)
            : 0.0;

        $unassigned = $issues->whereNull('assignee_id')->count();
        $overdue = $issues->filter(fn (Issue $issue) => $this->isOverdue($issue))->count();
        $decisionBacklog = $this->decisionsNeeded($pastMeetingsWithSummary)->count();

        $score = max(0, 100 - ($overdue * 10) - ($unassigned * 5) - ($decisionBacklog * 3));
        $status = $score >= 80 ? 'healthy' : ($score >= 60 ? 'warning' : 'risk');

        return [
            'score' => $score,
            'status' => $status,
            'indicators' => [
                [
                    'key' => 'meeting_coverage',
                    'label' => 'Meeting coverage',
                    'value' => $meetingCoverage.'%',
                ],
                [
                    'key' => 'unassigned_tasks',
                    'label' => 'Unassigned tasks',
                    'value' => $unassigned,
                ],
                [
                    'key' => 'overdue_tasks',
                    'label' => 'Overdue tasks',
                    'value' => $overdue,
                ],
                [
                    'key' => 'open_decisions',
                    'label' => 'Open decisions',
                    'value' => $decisionBacklog,
                ],
            ],
        ];
    }

    private function risksTab(Collection $issues, Collection $meetings): array
    {
        $items = collect();

        foreach ($issues->filter(fn (Issue $issue) => $this->isOverdue($issue))->sortBy('due_date')->take(5) as $issue) {
            $items->push([
                'id' => 'issue-'.$issue->id,
                'severity' => 'high',
                'title' => $issue->name,
                'subtitle' => $this->issueSubtitle($issue),
                'source' => 'task',
            ]);
        }

        $recentPastMeetings = $meetings
            ->filter(fn (CalendarEvent $event) => $event->starts_at?->gte(now()->subWeeks(2)) && $event->starts_at?->lt(now()))
            ->sortByDesc('starts_at');

        foreach ($recentPastMeetings->filter(fn (CalendarEvent $event) => $event->meetingSummary === null)->take(3) as $event) {
            $items->push([
                'id' => 'meeting-'.$event->id,
                'severity' => 'medium',
                'title' => $event->title,
                'subtitle' => 'No summary generated yet',
                'source' => 'meeting',
            ]);
        }

        return [
            'items' => $items->values()->all(),
        ];
    }

    private function sinceLastWeekSections(Collection $issues, Collection $meetings, Carbon $sinceDate): array
    {
        $completed = $issues->filter(fn (Issue $issue) => $issue->status === 'done' && $issue->updated_at?->gte($sinceDate));
        $inProgress = $issues->filter(fn (Issue $issue) => in_array($issue->status, ['open', 'in_progress', 'paused', 'review', 'reopen'], true) && ! $this->isOverdue($issue) && $issue->updated_at?->gte($sinceDate));
        $overdue = $issues->filter(fn (Issue $issue) => $this->isOverdue($issue));
        $new = $issues->filter(fn (Issue $issue) => $issue->status === 'open' && $issue->created_at?->gte($sinceDate));

        return [
            [
                'key' => 'completed',
                'label' => 'Completed',
                'tone' => 'success',
                'count' => $completed->count(),
                'items' => $completed->sortByDesc('updated_at')->take(10)->map(fn (Issue $issue) => $this->issueCard($issue))->values()->all(),
            ],
            [
                'key' => 'in_progress',
                'label' => 'In progress',
                'tone' => 'info',
                'count' => $inProgress->count(),
                'items' => $inProgress->sortByDesc('updated_at')->take(10)->map(fn (Issue $issue) => $this->issueCard($issue))->values()->all(),
            ],
            [
                'key' => 'overdue',
                'label' => 'Overdue',
                'tone' => 'danger',
                'count' => $overdue->count(),
                'items' => $overdue->sortBy('due_date')->take(10)->map(fn (Issue $issue) => $this->issueCard($issue))->values()->all(),
            ],
            [
                'key' => 'new',
                'label' => 'New tasks',
                'tone' => 'warning',
                'count' => $new->count(),
                'items' => $new->sortByDesc('created_at')->take(10)->map(fn (Issue $issue) => $this->issueCard($issue))->values()->all(),
            ],
        ];
    }

    private function deadlinesAndPriorities(Collection $issues): array
    {
        return $issues
            ->filter(fn (Issue $issue) => in_array($issue->status, ['open', 'in_progress', 'paused', 'review', 'reopen'], true))
            ->sortBy([
                fn (Issue $issue) => $issue->due_date ? 0 : 1,
                fn (Issue $issue) => $issue->due_date?->timestamp ?? PHP_INT_MAX,
                fn (Issue $issue) => $issue->updated_at?->timestamp ?? 0,
            ])
            ->values()
            ->take(10)
            ->map(fn (Issue $issue) => $this->issueCard($issue, true))
            ->all();
    }

    private function decisionsNeeded(Collection $meetings): Collection
    {
        $latestSummaryMeeting = $meetings
            ->filter(fn (CalendarEvent $event) => $event->meetingSummary?->status === 'done' && ! empty($event->meetingSummary?->decisions))
            ->sortByDesc('starts_at')
            ->first();

        if (! $latestSummaryMeeting) {
            return collect();
        }

        return collect($latestSummaryMeeting->meetingSummary->decisions)
            ->values()
            ->map(fn (string $decision, int $index) => [
                'id' => 'decision-'.$latestSummaryMeeting->meetingSummary->id.'-'.$index,
                'title' => $decision,
                'subtitle' => $latestSummaryMeeting->title,
                'meeting_date' => $latestSummaryMeeting->starts_at,
                'source' => 'summary',
            ]);
    }

    private function meetingCard(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            'platform' => $event->platform,
            'url' => $event->url,
            'meeting_link' => $event->url ? [
                'label' => 'Join meeting',
                'url' => $event->url,
            ] : null,
            'description' => $event->description,
            'participants_count' => $event->participants->count(),
            'has_summary' => $event->meetingSummary?->status === 'done',
            'summary_excerpt' => Str::limit((string) $event->meetingSummary?->summary, 180),
            'review_score' => $event->meetingReview?->score,
        ];
    }

    private function issueCard(Issue $issue, bool $withPriority = false): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->name,
            'description' => $issue->description,
            'status' => $issue->status,
            'assignee' => $issue->assignee ? [
                'id' => $issue->assignee->id,
                'name' => $issue->assignee->name,
            ] : ($issue->assignee_name ? ['name' => $issue->assignee_name] : null),
            'due_date' => $issue->due_date?->toDateString(),
            'meeting' => $issue->sourceable instanceof CalendarEvent ? [
                'id' => $issue->sourceable->id,
                'title' => $issue->sourceable->title,
                'starts_at' => $issue->sourceable->starts_at,
            ] : null,
            'priority' => $withPriority ? $this->priorityForIssue($issue) : null,
        ];
    }

    private function issueSubtitle(Issue $issue): string
    {
        $parts = [];

        if ($issue->assignee?->name) {
            $parts[] = $issue->assignee->name;
        } elseif ($issue->assignee_name) {
            $parts[] = $issue->assignee_name;
        }

        if ($issue->due_date) {
            $parts[] = 'Due '.$issue->due_date->toDateString();
        }

        if ($issue->sourceable instanceof CalendarEvent) {
            $parts[] = $issue->sourceable->title;
        }

        return implode(' · ', $parts);
    }

    private function priorityForIssue(Issue $issue): string
    {
        if ($this->isOverdue($issue)) {
            return 'high';
        }

        if ($issue->due_date && $issue->due_date->lte(now()->addDays(3))) {
            return 'medium';
        }

        return 'normal';
    }

    private function isOverdue(Issue $issue): bool
    {
        return $issue->status !== 'done'
            && $issue->due_date !== null
            && $issue->due_date->lt(now());
    }

    private function teamMeetings(Team $team): Collection
    {
        return CalendarEvent::query()
            ->where(function (Builder $query) use ($team): void {
                $query->whereHas('sources.user.teams', fn (Builder $q) => $q->where('teams.id', $team->id))
                    ->orWhereHas('participants.profile.user.teams', fn (Builder $q) => $q->where('teams.id', $team->id));
            })
            ->with([
                'meetingSummary',
                'meetingReview',
                'participants.profile.user',
                'sources.user',
                'agendas.user',
            ])
            ->orderByDesc('starts_at')
            ->get();
    }

    private function teamIssues(Team $team): Collection
    {
        return Issue::query()
            ->withoutTrashed()
            ->where(function (Builder $query) use ($team): void {
                $query->where('team_id', $team->id)
                    ->orWhere(function (Builder $inner) use ($team): void {
                        $inner->where('organization_id', $team->organization_id)
                            ->whereNull('team_id');
                    });
            })
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhere('status', '!=', 'cancelled');
            })
            ->with(['assignee', 'sourceable'])
            ->orderByDesc('updated_at')
            ->get();
    }

    private function meetingReadinessNotes(CalendarEvent $meeting, Team $team): array
    {
        $notes = [];

        if (! $meeting->meetingSummary) {
            $notes[] = 'No summary yet.';
        }

        if (! $meeting->meetingReview) {
            $notes[] = 'No review generated yet.';
        }

        if ($meeting->participants->isEmpty()) {
            $notes[] = 'No participants linked.';
        }

        if ($meeting->description === null && $meeting->agendas->isEmpty()) {
            $notes[] = 'No agenda found.';
        }

        return $notes;
    }
}
