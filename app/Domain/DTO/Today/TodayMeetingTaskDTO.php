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
        public readonly bool $is_epic = false,
        // Why this task surfaced on this meeting (shown in the task dropdown):
        //  - generated tasks: who formulated it + the "## Context" the extractor wrote;
        //  - updated tasks: who updated it + the merge note written for this meeting.
        public readonly ?string $author_name = null,
        public readonly ?string $context = null,
    ) {}
}
