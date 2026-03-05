<?php

namespace App\Domain\DTO\Dashboard;

use App\Domain\DTO\BaseDTO;
use Illuminate\Support\Collection;

class TeamStatsDTO extends BaseDTO
{
    public function __construct(
        public readonly int $total,
        public readonly Collection $list,
    ) {}

    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'list'  => $this->list->toArray(),
        ];
    }
}
