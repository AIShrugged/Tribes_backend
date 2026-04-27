<?php

namespace App\Domain\DTO\Issue;

use App\Domain\DTO\BaseDTO;

class IssueStatsDTO extends BaseDTO
{
    public function __construct(
        public readonly int $total,
        public readonly int $open,
        public readonly int $inProgress,
        public readonly int $paused,
        public readonly int $completed,
        public readonly int $overdue,
        public readonly int $deltaInProgress,
        public readonly int $deltaCompleted,
        public readonly int $deltaOverdue,
        public readonly int $closedToday = 0,
        public readonly int $closedThisWeek = 0,
        public readonly int $closedThisMonth = 0,
        public readonly int $closedAllTime = 0,
        public readonly int $deltaToday = 0,
        public readonly int $deltaWeek = 0,
        public readonly int $deltaMonth = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'total'             => $this->total,
            'in_progress'       => $this->inProgress,
            'completed'         => $this->completed,
            'overdue'           => $this->overdue,
            'open'              => $this->open,
            'paused'            => $this->paused,
            'closed_today'      => $this->closedToday,
            'closed_this_week'  => $this->closedThisWeek,
            'closed_this_month' => $this->closedThisMonth,
            'closed_all_time'   => $this->closedAllTime,
            'delta_today'       => $this->deltaToday,
            'delta_week'        => $this->deltaWeek,
            'delta_month'       => $this->deltaMonth,
            'delta'             => [
                'in_progress' => $this->deltaInProgress,
                'completed'   => $this->deltaCompleted,
                'overdue'     => $this->deltaOverdue,
            ],
        ];
    }
}
