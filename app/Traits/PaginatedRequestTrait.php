<?php

namespace App\Traits;

trait PaginatedRequestTrait
{
    public const DEFAULT_LIMIT = 10;
    public const DEFAULT_MAX_LIMIT = 50;

    public function getOffset(): int
    {
        $offset = $this->input('offset');
        if ($offset !== null) {
            return (int) $offset;
        }

        $page = $this->input('page');
        if ($page !== null) {
            $page = max(1, (int) $page);
            return ($page - 1) * $this->getLimit();
        }

        return 0;
    }

    public function getLimit(): int
    {
        return (int) $this->input('limit', self::DEFAULT_LIMIT);
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
