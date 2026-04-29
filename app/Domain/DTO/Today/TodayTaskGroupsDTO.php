<?php

namespace App\Domain\DTO\Today;

use App\Domain\DTO\BaseDTO;

class TodayTaskGroupsDTO extends BaseDTO
{
    public function __construct(
        public readonly array $focused,
        public readonly array $today,
        public readonly array $current,
        public readonly array $overdue,
    ) {}

    public function toArray(): array
    {
        return [
            'focused' => array_map(fn(TodayDeadlineTaskDTO $t) => $t->toArray(), $this->focused),
            'today'   => array_map(fn(TodayDeadlineTaskDTO $t) => $t->toArray(), $this->today),
            'current' => array_map(fn(TodayDeadlineTaskDTO $t) => $t->toArray(), $this->current),
            'overdue' => array_map(fn(TodayDeadlineTaskDTO $t) => $t->toArray(), $this->overdue),
        ];
    }

    public static function empty(): self
    {
        return new self([], [], [], []);
    }
}
