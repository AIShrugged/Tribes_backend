<?php

namespace App\Domain\DTO\Today;

use App\Domain\DTO\BaseDTO;

class TodayDeadlineTaskDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $status,
        public readonly int $priority,
        public readonly ?string $due_date,
        public readonly ?int $days_overdue,
        public readonly ?string $assignee_name,
    ) {}
}
