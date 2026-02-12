<?php

namespace App\Domain\DTO\Insight;

class InsightItemDataDTO
{
    public function __construct(
        public readonly string $category,
        public readonly string $fact,
        public readonly float $confidence,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            category:   $data['category'],
            fact:       $data['fact'],
            confidence: (float) ($data['confidence'] ?? 0.8),
        );
    }
}
