<?php

namespace App\Domain\DTO\Insight;

class InsightShortTermDataDTO
{
    public function __construct(
        public readonly string $contextType,
        public readonly array $content,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            contextType: $data['context_type'],
            content:     $data['content'] ?? [],
        );
    }
}
