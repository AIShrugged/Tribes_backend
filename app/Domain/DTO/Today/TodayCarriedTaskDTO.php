<?php

namespace App\Domain\DTO\Today;

use App\Domain\DTO\BaseDTO;

class TodayCarriedTaskDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $status,
        public readonly ?int $assignee_id,
        public readonly ?string $assignee_name,
        public readonly string $source_meeting_title,
        public readonly string $source_meeting_date,
        public readonly int $syncs_since_created,
    ) {}
}
