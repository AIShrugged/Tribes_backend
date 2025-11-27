<?php

namespace App\Services\Recall\Payloads;

use App\Services\Recall;
use App\Services\Recall\RecallPayloadInterface;

class CalendarUpdatePayload implements RecallPayloadInterface
{
    public function __construct(
        public string $calendarId,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new static($data['calendar_id']);
    }
}
