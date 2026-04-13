<?php

namespace App\Domain\DTO\Today;

use App\Domain\DTO\BaseDTO;

class TodayMeetingSummaryDTO extends BaseDTO
{
    public function __construct(
        public readonly string $title,
        public readonly string $summary,
        public readonly array $key_points,
        public readonly array $decisions,
        public readonly array $attendees,
    ) {}
}
