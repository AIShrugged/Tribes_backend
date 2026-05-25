<?php

namespace App\Domain\DTO;

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

    public function withTimings(
        float $startRelative,
        ?string $startAbsolute,
        float $endRelative,
        ?string $endAbsolute,
    ): self {
        return new self(
            speaker: $this->speaker,
            paragraph: $this->paragraph,
            startRelative: $startRelative,
            startAbsolute: $startAbsolute,
            endRelative: $endRelative,
            endAbsolute: $endAbsolute,
            durationSeconds: $endRelative - $startRelative,
        );
    }
}
