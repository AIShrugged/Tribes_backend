<?php

namespace App\Domain\DTO\Today;

use App\Domain\DTO\BaseDTO;

class TodayMeetingTaskDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $status,
        public readonly ?string $assignee_name,
        public readonly ?int $assignee_id,
        public readonly ?string $due_date,
        public readonly bool $is_overdue,
    ) {}
}
