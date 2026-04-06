<?php

namespace App\Domain\DTO\Today;

use App\Domain\DTO\BaseDTO;

class TodayMeetingReviewDTO extends BaseDTO
{
    public function __construct(
        public readonly ?string $key_insight,
        public readonly array $suggestions,
    ) {}
}
