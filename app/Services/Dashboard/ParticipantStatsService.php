<?php

namespace App\Services\Dashboard;

use App\Domain\DTO\Dashboard\ParticipantStatsDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ParticipantStatsService
{
    public function getStats(Collection $eventIds, int $totalMeetings): ParticipantStatsDTO
    {
        return new ParticipantStatsDTO(
            totalUnique:        $this->countUnique($eventIds),
            averagePerMeeting:  $this->averagePerMeeting($eventIds, $totalMeetings),
            top:                $this->topParticipants($eventIds),
        );
    }

    private function countUnique(Collection $eventIds): int
    {
        $withProfile = DB::table('participants')
            ->whereIn('calendar_event_id', $eventIds)
            ->whereNotNull('profile_id')
            ->distinct()
            ->count('profile_id');

        $withoutProfile = DB::table('participants')
            ->whereIn('calendar_event_id', $eventIds)
            ->whereNull('profile_id')
            ->distinct()
            ->count('name');

        return $withProfile + $withoutProfile;
    }

    private function averagePerMeeting(Collection $eventIds, int $totalMeetings): float
    {
        if ($totalMeetings === 0) {
            return 0.0;
        }

        $total = DB::table('participants')
            ->whereIn('calendar_event_id', $eventIds)
            ->count();

        return round($total / $totalMeetings, 1);
    }

    private function topParticipants(Collection $eventIds): Collection
    {
        return DB::table('participants')
            ->whereIn('calendar_event_id', $eventIds)
            ->select('name', DB::raw('COUNT(*) as meetings_count'))
            ->groupBy('name')
            ->orderByDesc('meetings_count')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'name'           => $p->name,
                'meetings_count' => (int) $p->meetings_count,
            ]);
    }
}
