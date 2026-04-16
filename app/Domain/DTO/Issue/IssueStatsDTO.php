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
    ) {}

    public function toArray(): array
    {
        return [
            'total'       => $this->total,
            'in_progress' => $this->inProgress,
            'completed'   => $this->completed,
            'overdue'     => $this->overdue,
            'open'        => $this->open,
            'paused'      => $this->paused,
            'delta'       => [
                'in_progress' => $this->deltaInProgress,
                'completed'   => $this->deltaCompleted,
                'overdue'     => $this->deltaOverdue,
            ],
        ];
    }
}
