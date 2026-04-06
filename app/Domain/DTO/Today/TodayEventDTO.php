<?php

namespace App\Domain\DTO\Today;

use App\Domain\DTO\BaseDTO;

class TodayEventDTO extends BaseDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $starts_at,
        public readonly string $ends_at,
        public readonly int $participants_count,
        public readonly ?string $platform,
        public readonly ?string $meeting_url,
        public readonly string $meeting_state,
        public readonly ?TodayMeetingSummaryDTO $summary,
        public readonly ?TodayMeetingReviewDTO $review,
        public readonly array $tasks,
        public readonly int $total_tasks_count,
        public readonly int $done_tasks_count,
        public readonly ?string $agenda_content,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'participants_count' => $this->participants_count,
            'platform' => $this->platform,
            'meeting_url' => $this->meeting_url,
            'meeting_state' => $this->meeting_state,
            'summary' => $this->summary?->toArray(),
            'review' => $this->review?->toArray(),
            'tasks' => array_map(fn(TodayMeetingTaskDTO $t) => $t->toArray(), $this->tasks),
            'total_tasks_count' => $this->total_tasks_count,
            'done_tasks_count' => $this->done_tasks_count,
            'agenda_content' => $this->agenda_content,
        ];
    }
}
