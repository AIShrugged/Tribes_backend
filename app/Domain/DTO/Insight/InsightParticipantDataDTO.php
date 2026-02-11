<?php

namespace App\Domain\DTO\Insight;

class InsightParticipantDataDTO
{
    /**
     * @param  InsightItemDataDTO[]      $items
     * @param  InsightShortTermDataDTO[] $shortTerm
     */
    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly array $items,
        public readonly array $shortTerm,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            email:     $data['email'],
            name:      $data['name'] ?? '',
            items:     array_map(
                fn($i) => InsightItemDataDTO::fromArray($i),
                $data['items'] ?? []
            ),
            shortTerm: array_map(
                fn($s) => InsightShortTermDataDTO::fromArray($s),
                $data['short_term'] ?? []
            ),
        );
    }
}
