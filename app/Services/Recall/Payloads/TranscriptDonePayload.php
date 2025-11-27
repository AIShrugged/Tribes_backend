<?php

namespace App\Services\Recall\Payloads;

use App\Services\Recall;
use App\Services\Recall\RecallPayloadInterface;

class TranscriptDonePayload implements RecallPayloadInterface
{
    public function __construct(
        public string $botId,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new static($data['bot']['id']);
    }
}
