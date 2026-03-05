<?php

namespace App\Services\Dashboard;

use App\Domain\DTO\Dashboard\MeetingStatsDTO;
use App\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MeetingStatsService
{
    public function getStats(Collection $sourceIds): MeetingStatsDTO
    {
        $query = CalendarEvent::whereIn('source_id', $sourceIds);

        return new MeetingStatsDTO(
            total:                    $this->countTotal($query),
            withBot:                  $this->countWithBot($query),
            totalDurationMinutes:     $this->totalDurationMinutes($query),
            averageDurationMinutes:   $this->averageDurationMinutes($query),
            recent:                   $this->recentMeetings($query),
            byMonth:                  $this->byMonth($query),
        );
    }

    private function countTotal(Builder $query): int
    {
        return (clone $query)->count();
    }

    private function countWithBot(Builder $query): int
    {
        return (clone $query)->where('required_bot', true)->count();
    }

    private function totalDurationMinutes(Builder $query): int
    {
        return (int) (clone $query)
            ->selectRaw('SUM(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60) as total')
            ->value('total');
    }

    private function averageDurationMinutes(Builder $query): int
    {
        return (int) round(
            (clone $query)
                ->selectRaw('AVG(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60) as avg')
                ->value('avg') ?? 0
        );
    }

    private function recentMeetings(Builder $query): Collection
    {
        return (clone $query)
            ->select('id', 'title', 'starts_at', 'ends_at')
            ->selectRaw('EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60 as duration_minutes')
            ->withCount('participants')
            ->orderByDesc('starts_at')
            ->limit(10)
            ->get()
            ->map(fn ($e) => [
                'id'                => $e->id,
                'title'             => $e->title,
                'starts_at'         => $e->starts_at,
                'ends_at'           => $e->ends_at,
                'duration_minutes'  => (int) $e->duration_minutes,
                'participants_count' => $e->participants_count,
            ]);
    }

    private function byMonth(Builder $query): Collection
    {
        return (clone $query)
            ->selectRaw("TO_CHAR(starts_at, 'YYYY-MM') as month")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60) as total_duration_minutes')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($r) => [
                'month'                  => $r->month,
                'count'                  => (int) $r->count,
                'total_duration_minutes' => (int) $r->total_duration_minutes,
            ]);
    }
}
