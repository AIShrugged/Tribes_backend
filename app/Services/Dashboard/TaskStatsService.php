<?php

namespace App\Services\Dashboard;

use App\Domain\DTO\Dashboard\TaskStatsDTO;
use App\Models\MeetingTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaskStatsService
{
    public function getStats(Collection $eventIds): TaskStatsDTO
    {
        $query = MeetingTask::query()
            ->where('sourceable_type', \App\Models\CalendarEvent::class)
            ->whereIn('sourceable_id', $eventIds);

        $byStatus = $this->countByStatus($query);

        return new TaskStatsDTO(
            total:      (clone $query)->count(),
            open:       (int) ($byStatus['open'] ?? 0),
            inProgress: (int) ($byStatus['in_progress'] ?? 0),
            paused:     (int) ($byStatus['paused'] ?? 0),
            done:       (int) ($byStatus['done'] ?? 0),
            overdue:    $this->countOverdue($query),
        );
    }

    private function countByStatus(Builder $query): array
    {
        return (clone $query)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    private function countOverdue(Builder $query): int
    {
        return (clone $query)
            ->where('status', '!=', 'done')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now())
            ->count();
    }
}
