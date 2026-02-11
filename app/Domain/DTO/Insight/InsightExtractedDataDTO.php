<?php

namespace App\Domain\DTO\Insight;

class InsightExtractedDataDTO
{
    /**
     * @param  InsightParticipantDataDTO[]   $participants
     * @param  InsightRelationshipDataDTO[]  $relationships
     */
    public function __construct(
        public readonly array $participants,
        public readonly array $relationships,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            participants:  array_map(
                fn($p) => InsightParticipantDataDTO::fromArray($p),
                $data['participants'] ?? []
            ),
            relationships: array_map(
                fn($r) => InsightRelationshipDataDTO::fromArray($r),
                $data['relationships'] ?? []
            ),
        );
    }
}
