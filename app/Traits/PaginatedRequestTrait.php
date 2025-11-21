<?php

namespace App\Traits;

trait PaginatedRequestTrait
{
    public const DEFAULT_LIMIT = 10;

    public function getOffset(): int
    {
        return $this->input('offset', 0);
    }

    public function getLimit(): int
    {
        return $this->input('limit', self::DEFAULT_LIMIT);
    }
}
