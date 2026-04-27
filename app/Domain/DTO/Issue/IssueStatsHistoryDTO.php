<?php

namespace App\Domain\DTO\Issue;

use App\Domain\DTO\BaseDTO;

class IssueStatsHistoryDTO extends BaseDTO
{
    /**
     * @param  array<int, array{date: string, closed: int}>  $items
     */
    public function __construct(
        public readonly string $period,
        public readonly int $range,
        public readonly array $items,
    ) {}

    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'range'  => $this->range,
            'items'  => $this->items,
        ];
    }
}