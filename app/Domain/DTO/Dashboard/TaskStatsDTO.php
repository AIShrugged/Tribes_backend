<?php

namespace App\Domain\DTO\Dashboard;

use App\Domain\DTO\BaseDTO;

class TaskStatsDTO extends BaseDTO
{
    public function __construct(
        public readonly int $total,
        public readonly int $open,
        public readonly int $inProgress,
        public readonly int $done,
        public readonly int $cancelled,
        public readonly int $overdue,
    ) {}

    public function toArray(): array
    {
        return [
            'total'     => $this->total,
            'by_status' => [
                'open'        => $this->open,
                'in_progress' => $this->inProgress,
                'done'        => $this->done,
                'cancelled'   => $this->cancelled,
            ],
            'overdue'   => $this->overdue,
        ];
    }
}
