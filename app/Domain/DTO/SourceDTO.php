<?php

namespace App\Domain\DTO;

class SourceDTO extends BaseDTO
{
    public function __construct(
        public string $externalId,
        public string $identity,
        public string $type,
    )
    {
    }
}
