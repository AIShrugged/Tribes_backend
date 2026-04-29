<?php

namespace App\Domain\DTO\Today;

use App\Domain\DTO\BaseDTO;

class TodayBriefingDTO extends BaseDTO
{
    public function __construct(
        public readonly string $state,
        public readonly string $date,
        public readonly array $events,
        public readonly array $carried_tasks,
        public readonly array $waiting_on_you,
        public readonly array $stale,
        public readonly ?string $nudge,
        public readonly TodayTaskGroupsDTO $task_groups,
    ) {}

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'date' => $this->date,
            'events' => array_map(fn(TodayEventDTO $e) => $e->toArray(), $this->events),
            'carried_tasks' => array_map(fn(TodayCarriedTaskDTO $t) => $t->toArray(), $this->carried_tasks),
            'waiting_on_you' => array_map(fn(TodayWaitingTaskDTO $t) => $t->toArray(), $this->waiting_on_you),
            'stale' => array_map(fn(TodayStaleTaskDTO $t) => $t->toArray(), $this->stale),
            'nudge' => $this->nudge,
            'task_groups' => $this->task_groups->toArray(),
        ];
    }
}
