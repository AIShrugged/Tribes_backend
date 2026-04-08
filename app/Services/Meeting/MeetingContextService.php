<?php

namespace App\Services\Meeting;

use App\Models\CalendarEvent;
use App\Models\Issue;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MeetingContextService
{
    /**
     * Find previous events in the same series (matched by title + url).
     * Pattern from PreMeetingBriefService::findPreviousEvent().
     */
    public function findPreviousEvents(CalendarEvent $event, int $limit = 10): Collection
    {
        return CalendarEvent::query()
            ->where('title', $event->title)
            ->where('url', $event->url)
            ->where('starts_at', '<', $event->starts_at)
            ->orderByDesc('starts_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Find the most recent previous event in the series that has any tasks.
     */
    public function findPreviousEventWithTasks(CalendarEvent $event): ?CalendarEvent
    {
        return CalendarEvent::query()
            ->where('title', $event->title)
            ->where('url', $event->url)
            ->where('starts_at', '<', $event->starts_at)
            ->whereHas('issues', fn($q) => $q->withoutTrashed()->whereNotIn('status', ['cancelled']))
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * Find the most recent previous event with a completed summary.
     */
    public function findPreviousEventWithSummary(CalendarEvent $event): ?CalendarEvent
    {
        return CalendarEvent::query()
            ->where('title', $event->title)
            ->where('url', $event->url)
            ->where('starts_at', '<', $event->starts_at)
            ->whereHas('meetingSummary', fn($q) => $q->where('status', 'done'))
            ->orderByDesc('starts_at')
            ->with('meetingSummary')
            ->first();
    }

    /**
     * Get IDs of previous events in the same series.
     */
    public function getSeriesEventIds(CalendarEvent $event): Collection
    {
        return CalendarEvent::query()
            ->where('title', $event->title)
            ->where('url', $event->url)
            ->where('starts_at', '<', $event->starts_at)
            ->pluck('id');
    }

    /**
     * Get open tasks (carried) from previous events in the same series.
     * Statuses: everything except 'done' and 'cancelled'.
     * Optional teamId filter for team-scoped queries.
     */
    public function getCarriedTasks(CalendarEvent $event, ?int $teamId = null): Collection
    {
        $eventIds = $this->getSeriesEventIds($event);

        if ($eventIds->isEmpty()) {
            return collect();
        }

        $query = Issue::query()
            ->withoutTrashed()
            ->where('sourceable_type', CalendarEvent::class)
            ->whereIn('sourceable_id', $eventIds)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->with(['assignee', 'sourceable']);

        if ($teamId !== null) {
            $query->where(fn($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'));
        }

        return $query->get();
    }

    /**
     * Get tasks completed between two events in the same series.
     */
    public function getCompletedTasksBetween(CalendarEvent $event, CalendarEvent $previousEvent, ?int $teamId = null): Collection
    {
        $eventIds = $this->getSeriesEventIds($event);

        if ($eventIds->isEmpty()) {
            return collect();
        }

        $query = Issue::query()
            ->withoutTrashed()
            ->where('sourceable_type', CalendarEvent::class)
            ->whereIn('sourceable_id', $eventIds)
            ->where('status', 'done')
            ->where('updated_at', '>=', $previousEvent->starts_at)
            ->with('assignee');

        if ($teamId !== null) {
            $query->where(fn($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'));
        }

        return $query->get();
    }

    /**
     * Count how many events in the same series have occurred since the task was created.
     */
    public function countSyncsSinceCreated(Issue $issue): int
    {
        $sourceEvent = $issue->sourceable;

        if (! $sourceEvent instanceof CalendarEvent) {
            return 0;
        }

        $taskCreatedAt = $issue->registration_date ?? $issue->created_at;

        return CalendarEvent::query()
            ->where('title', $sourceEvent->title)
            ->where('url', $sourceEvent->url)
            ->where('starts_at', '>', $taskCreatedAt)
            ->count();
    }

    /**
     * Batch-count syncs_since_created for a collection of issues.
     * More efficient than calling countSyncsSinceCreated() per issue.
     */
    public function batchCountSyncsSinceCreated(Collection $issues): array
    {
        $counts = [];

        // Group issues by their source event's title+url to batch queries
        $grouped = $issues->groupBy(function (Issue $issue) {
            $event = $issue->sourceable;
            if (! $event instanceof CalendarEvent) {
                return '__no_event__';
            }
            return $event->title . '|||' . $event->url;
        });

        foreach ($grouped as $key => $groupIssues) {
            if ($key === '__no_event__') {
                foreach ($groupIssues as $issue) {
                    $counts[$issue->id] = 0;
                }
                continue;
            }

            $firstEvent = $groupIssues->first()->sourceable;

            // Get all event dates in this series
            $eventDates = CalendarEvent::query()
                ->where('title', $firstEvent->title)
                ->where('url', $firstEvent->url)
                ->orderBy('starts_at')
                ->pluck('starts_at');

            foreach ($groupIssues as $issue) {
                $taskCreatedAt = $issue->registration_date ?? $issue->created_at;
                $counts[$issue->id] = $eventDates->filter(
                    fn($date) => Carbon::parse($date)->gt(Carbon::parse($taskCreatedAt))
                )->count();
            }
        }

        return $counts;
    }
}
