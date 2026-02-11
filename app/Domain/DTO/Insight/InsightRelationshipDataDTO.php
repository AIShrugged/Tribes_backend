<?php

namespace App\Domain\DTO\Insight;

class InsightRelationshipDataDTO
{
    public function __construct(
        public readonly string $emailA,
        public readonly string $emailB,
        public readonly string $observation,
        public readonly string $relationshipType,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            emailA:           $data['email_a'],
            emailB:           $data['email_b'],
            observation:      $data['observation'] ?? '',
            relationshipType: $data['relationship_type'] ?? 'neutral',
        );
    }
}
