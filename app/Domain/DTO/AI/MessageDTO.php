<?php

namespace App\Domain\DTO\AI;

use App\Domain\DTO\BaseDTO;

class MessageDTO extends BaseDTO
{
    public function __construct(
        public string $role,
        public string $content,
    ) {
    }

    public function toArray(): array
    {
        return [
            'role'    => $this->role,
            'content' => $this->content,
        ];
    }
}
