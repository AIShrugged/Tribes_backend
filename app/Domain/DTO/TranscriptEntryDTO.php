<?php

namespace App\Domain\DTO;

use App\Domain\DTO\BaseDTO;

class TranscriptEntryDTO extends BaseDTO
{
    public function __construct(
        public readonly string $speaker,
        public readonly string $paragraph,
        public readonly ?float $startRelative,
        public readonly ?string $startAbsolute,
        public readonly ?float $endRelative,
        public readonly ?string $endAbsolute,
        public readonly ?float $durationSeconds,
    ) {
    }
}
