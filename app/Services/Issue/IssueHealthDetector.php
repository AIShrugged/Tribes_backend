<?php

namespace App\Services\Issue;

use App\Models\Issue;
use App\Models\Team;
use Carbon\Carbon;

class IssueHealthDetector
{
    private const PRIORITY_IGNORED_DAYS = 3;

    private const TERMINAL_STATUSES = ['done', 'closed', 'cancelled'];

    private const ACTIVE_STATUSES = ['open', 'in_progress', 'paused', 'review', 'reopen'];

    public function detect(Team $team): array
    {
        $today = today();
        $endOfWeek = $today->copy()->endOfWeek(Carbon::FRIDAY);
        $staleThreshold = now()->subDays(self::PRIORITY_IGNORED_DAYS);

        $overdue = Issue::query()
            ->where('team_id', $team->id)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->withoutTrashed()
            ->with('assignee:id,name')
            ->get()
            ->map(fn (Issue $i) => [
                'issue_id'      => $i->id,
                'name'          => mb_substr($i->name, 0, 200),
                'due_date'      => $i->due_date->toDateString(),
                'assignee_name' => $i->assignee?->name,
                'days_overdue'  => $i->due_date->diffInDays($today),
            ])->values()->all();

        $sprintRisk = Issue::query()
            ->where('team_id', $team->id)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today, $endOfWeek])
            ->where('status', 'open')
            ->withoutTrashed()
            ->with('assignee:id,name')
            ->get()
            ->map(fn (Issue $i) => [
                'issue_id'       => $i->id,
                'name'           => mb_substr($i->name, 0, 200),
                'due_date'       => $i->due_date->toDateString(),
                'status'         => $i->status,
                'days_until_due' => $today->diffInDays($i->due_date),
                'assignee_name'  => $i->assignee?->name,
            ])->values()->all();

        $priorityIgnored = Issue::query()
            ->where('team_id', $team->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('priority', '>=', Issue::PRIORITY_HIGH)
            ->withoutTrashed()
            ->withMax('allComments as latest_comment_at', 'created_at')
            ->get()
            ->filter(function (Issue $issue) use ($staleThreshold) {
                if (! $issue->assignee_id) {
                    return true;
                }
                $lastMovement = $this->lastMovement($issue);

                return $lastMovement->lt($staleThreshold);
            })
            ->map(fn (Issue $i) => [
                'issue_id'       => $i->id,
                'name'           => mb_substr($i->name, 0, 200),
                'priority'       => $i->priority,
                'priority_label' => $this->priorityLabel($i->priority),
                'days_inactive'  => $this->lastMovement($i)->diffInDays(now()),
                'has_assignee'   => (bool) $i->assignee_id,
            ])->values()->all();

        $totalAnalyzed = Issue::where('team_id', $team->id)
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->withoutTrashed()
            ->count();

        return [
            'schema_version' => 1,
            'counts'         => [
                'total_analyzed'   => $totalAnalyzed,
                'overdue'          => count($overdue),
                'sprint_risk'      => count($sprintRisk),
                'priority_ignored' => count($priorityIgnored),
            ],
            'overdue'          => $overdue,
            'sprint_risk'      => $sprintRisk,
            'priority_ignored' => $priorityIgnored,
        ];
    }

    public function computeLiveCounts(Team $team): array
    {
        $today = today();
        $staleThreshold = now()->subDays(self::PRIORITY_IGNORED_DAYS);

        return [
            'overdue' => Issue::query()
                ->where('team_id', $team->id)
                ->whereNotNull('due_date')
                ->where('due_date', '<', $today->toDateString())
                ->whereNotIn('status', self::TERMINAL_STATUSES)
                ->withoutTrashed()
                ->count(),

            'due_today' => Issue::query()
                ->where('team_id', $team->id)
                ->where('due_date', $today->toDateString())
                ->whereNotIn('status', self::TERMINAL_STATUSES)
                ->withoutTrashed()
                ->count(),

            'due_this_week' => Issue::query()
                ->where('team_id', $team->id)
                ->whereNotNull('due_date')
                ->whereBetween('due_date', [
                    $today->copy()->addDay()->toDateString(),
                    $today->copy()->addDays(7)->toDateString(),
                ])
                ->whereNotIn('status', self::TERMINAL_STATUSES)
                ->withoutTrashed()
                ->count(),

            'critical_ignored' => Issue::query()
                ->where('team_id', $team->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->where('priority', '>=', Issue::PRIORITY_CRITICAL)
                ->withoutTrashed()
                ->where(function ($q) use ($staleThreshold): void {
                    $q->where('updated_at', '<', $staleThreshold)
                        ->whereDoesntHave('allComments', fn ($q) => $q->where('created_at', '>=', $staleThreshold));
                })
                ->count(),
        ];
    }

    public function hasProblems(array $findings): bool
    {
        return $findings['counts']['overdue'] > 0
            || $findings['counts']['sprint_risk'] > 0
            || $findings['counts']['priority_ignored'] > 0;
    }

    private function priorityLabel(int $priority): string
    {
        return match (true) {
            $priority >= Issue::PRIORITY_CRITICAL => 'critical',
            $priority >= Issue::PRIORITY_HIGH     => 'high',
            default                               => 'normal',
        };
    }

    private function lastMovement(Issue $issue): Carbon
    {
        $updated = Carbon::parse($issue->updated_at);
        $comment = $issue->latest_comment_at ? Carbon::parse($issue->latest_comment_at) : null;

        return $comment && $comment->gt($updated) ? $comment : $updated;
    }
}
