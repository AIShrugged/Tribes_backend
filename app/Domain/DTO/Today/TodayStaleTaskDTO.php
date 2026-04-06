<?php

namespace App\Domain\DTO\Today;

use App\Domain\DTO\BaseDTO;

class TodayStaleTaskDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $assignee_name,
        public readonly ?string $description,
        public readonly int $syncs_since_created,
    ) {}
}
