<?php

namespace App\Domain\DTO\Dashboard;

use App\Domain\DTO\BaseDTO;
use Illuminate\Support\Collection;

class ParticipantStatsDTO extends BaseDTO
{
    public function __construct(
        public readonly int $totalUnique,
        public readonly float $averagePerMeeting,
        public readonly Collection $top,
    ) {}

    public function toArray(): array
    {
        return [
            'total_unique'          => $this->totalUnique,
            'average_per_meeting'   => $this->averagePerMeeting,
            'top'                   => $this->top->toArray(),
        ];
    }
}
