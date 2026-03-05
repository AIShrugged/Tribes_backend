<?php

namespace App\Domain\DTO\Dashboard;

use App\Domain\DTO\BaseDTO;

class FollowupStatsDTO extends BaseDTO
{
    public function __construct(
        public readonly int $total,
        public readonly int $done,
        public readonly int $inProgress,
        public readonly int $failed,
    ) {}

    public function toArray(): array
    {
        return [
            'total'     => $this->total,
            'by_status' => [
                'done'        => $this->done,
                'in_progress' => $this->inProgress,
                'failed'      => $this->failed,
            ],
        ];
    }
}
