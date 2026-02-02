<?php

namespace App\Domain\DTO;

class EmailDTO extends BaseDTO
{
    public function __construct(
        public readonly string $from,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly string $subject,
        public readonly string $htmlBody,
        public readonly array $attachments = [],
    ) {
    }
}
