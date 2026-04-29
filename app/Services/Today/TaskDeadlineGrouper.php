<?php

namespace App\Services\Today;

use App\Models\Issue;
use App\Models\User;
use App\Services\UserFocusService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TaskDeadlineGrouper
{
    public function __construct(private readonly UserFocusService $userFocusService) {}

    public function groupForUser(User $user): array
    {
        $focused = $this->userFocusService->getFocusedIssues($user);
        $focusedIds = $focused->pluck('id')->all();

        $issues = Issue::query()
            ->where('assignee_id', $user->id)
            ->whereNotIn('status', ['done', 'closed', 'cancelled'])
            ->when(! empty($focusedIds), fn($q) => $q->whereNotIn('id', $focusedIds))
            ->with('assignee')
            ->get();

        $groups = $this->group($issues);
        $groups['focused'] = $focused; // user-given order preserved by service

        return $groups;
    }

    public function group(Collection $issues): array
    {
        $today = Carbon::today();
        $groups = ['overdue' => collect(), 'today' => collect(), 'current' => collect()];

        foreach ($issues as $issue) {
            $due = $issue->due_date ? Carbon::parse($issue->due_date) : null;

            if ($due && $due->lt($today)) {
                $groups['overdue']->push($issue);
            } elseif ($due && $due->isSameDay($today)) {
                $groups['today']->push($issue);
            } else {
                $groups['current']->push($issue);
            }
        }

        foreach ($groups as $key => $collection) {
            $groups[$key] = $collection
                ->sort(fn(Issue $a, Issue $b) => $this->compare($a, $b))
                ->values();
        }

        return $groups;
    }

    private function compare(Issue $a, Issue $b): int
    {
        $byPriority = ($b->priority ?? 0) <=> ($a->priority ?? 0);
        if ($byPriority !== 0) {
            return $byPriority;
        }

        if ($a->due_date === null && $b->due_date === null) return 0;
        if ($a->due_date === null) return 1;
        if ($b->due_date === null) return -1;

        return Carbon::parse($a->due_date) <=> Carbon::parse($b->due_date);
    }
}
