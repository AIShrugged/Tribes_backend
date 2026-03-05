<?php

namespace App\Services\Dashboard;

use App\Domain\DTO\Dashboard\DashboardStatsDTO;
use App\Models\CalendarEvent;
use App\Models\MeetingSummary;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(
        private readonly MeetingStatsService $meetingStats,
        private readonly ParticipantStatsService $participantStats,
        private readonly TaskStatsService $taskStats,
        private readonly FollowupStatsService $followupStats,
        private readonly TeamStatsService $teamStats,
    ) {}

    public function getStats(User $user): DashboardStatsDTO
    {
        $sourceIds = $this->resolveSourceIds($user->id);
        $eventIds  = $this->resolveEventIds($sourceIds);

        $meetings = $this->meetingStats->getStats($sourceIds);

        return new DashboardStatsDTO(
            meetings:       $meetings,
            participants:   $this->participantStats->getStats($eventIds, $meetings->total),
            tasks:          $this->taskStats->getStats($eventIds),
            followups:      $this->followupStats->getStats($user->id),
            summariesTotal: $this->countSummaries($eventIds),
            teams:          $this->teamStats->getStats($user),
        );
    }

    private function resolveSourceIds(int $userId): Collection
    {
        return Source::owned($userId)->pluck('id');
    }

    private function resolveEventIds(Collection $sourceIds): Collection
    {
        return CalendarEvent::whereIn('source_id', $sourceIds)->pluck('id');
    }

    private function countSummaries(Collection $eventIds): int
    {
        return MeetingSummary::whereIn('calendar_event_id', $eventIds)->count();
    }
}
