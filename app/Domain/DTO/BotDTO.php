<?php

namespace App\Domain\DTO;

class BotDTO
{
    public function __construct(
        public string $externalId,
        public string $deduplicationKey
    )
    {
    }
}
