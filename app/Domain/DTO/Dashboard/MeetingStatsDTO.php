<?php

namespace App\Domain\DTO\Dashboard;

use App\Domain\DTO\BaseDTO;
use Illuminate\Support\Collection;

class MeetingStatsDTO extends BaseDTO
{
    public function __construct(
        public readonly int $total,
        public readonly int $withBot,
        public readonly int $totalDurationMinutes,
        public readonly int $averageDurationMinutes,
        public readonly Collection $recent,
        public readonly Collection $byMonth,
    ) {}

    public function toArray(): array
    {
        return [
            'total'                    => $this->total,
            'with_bot'                 => $this->withBot,
            'total_duration_minutes'   => $this->totalDurationMinutes,
            'average_duration_minutes' => $this->averageDurationMinutes,
            'recent'                   => $this->recent->toArray(),
            'by_month'                 => $this->byMonth->toArray(),
        ];
    }
}
