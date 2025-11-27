<?php

namespace App\Domain\DTO;

class ProfileDTO extends BaseDTO
{
    public function __construct(
        public string $email,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self($data['email']);
    }
}
