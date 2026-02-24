<?php

namespace App\Traits;

trait PaginatedRequestTrait
{
    public const DEFAULT_LIMIT = 10;
    public const DEFAULT_MAX_LIMIT = 50;

    public function getOffset(): int
    {
        return $this->input('offset', 0);
    }

    public function getLimit(): int
    {
        return $this->input('limit', self::DEFAULT_LIMIT);
    }

    public function getPaginationRules(int $maxLimit = self::DEFAULT_MAX_LIMIT): array
    {
        return [
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit'  => ['nullable', 'integer', 'min:' . $maxLimit],
        ];
    }

    public function queryParameters(): array
    {
        return [
            'offset' => [
                'description' => 'Number of items to skip (for pagination).',
                'example'     => 0,
            ],
            'limit' => [
                'description' => 'Maximum number of items to return.',
                'example'     => 20,
            ],
        ];
    }

}
