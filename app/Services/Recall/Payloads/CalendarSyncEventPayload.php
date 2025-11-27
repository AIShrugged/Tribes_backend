<?php

namespace App\Services\Recall\Payloads;

use App\Services\Recall;
use App\Services\Recall\RecallPayloadInterface;

class CalendarSyncEventPayload implements RecallPayloadInterface
{
    public function __construct(
        public string $calendarId,
        public string $lastUpdated,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new static($data['calendar_id'], $data['last_updated_ts']);
    }
}
