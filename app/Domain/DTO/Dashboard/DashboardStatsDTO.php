<?php

namespace App\Domain\DTO\Dashboard;

use App\Domain\DTO\BaseDTO;

class DashboardStatsDTO extends BaseDTO
{
    public function __construct(
        public readonly MeetingStatsDTO $meetings,
        public readonly ParticipantStatsDTO $participants,
        public readonly TaskStatsDTO $tasks,
        public readonly FollowupStatsDTO $followups,
        public readonly int $summariesTotal,
        public readonly TeamStatsDTO $teams,
    ) {}

    public function toArray(): array
    {
        return [
            'meetings'     => $this->meetings->toArray(),
            'participants' => $this->participants->toArray(),
            'tasks'        => $this->tasks->toArray(),
            'followups'    => $this->followups->toArray(),
            'summaries'    => ['total' => $this->summariesTotal],
            'teams'        => $this->teams->toArray(),
        ];
    }
}
