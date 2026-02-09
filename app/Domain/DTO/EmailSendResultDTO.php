<?php

namespace App\Domain\DTO;

class EmailSendResultDTO extends BaseDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
    ) {
    }
}
