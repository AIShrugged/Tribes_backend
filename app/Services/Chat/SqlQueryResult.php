<?php

namespace App\Services\Chat;

class SqlQueryResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $data = [],
        public readonly int $rowCount = 0,
        public readonly ?string $error = null,
    ) {
    }
}
