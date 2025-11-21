<?php

namespace App\Domain\DTO;

class OauthDTO extends BaseDTO
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
        public string $email,
    ) {
    }
}
