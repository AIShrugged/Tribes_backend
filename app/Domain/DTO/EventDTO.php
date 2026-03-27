<?php

namespace App\Domain\DTO;

class EventDTO extends BaseDTO
{
    public function __construct(
        public ?string $externalId,
        public string $platform,
        public string $startsAt,
        public string $endsAt,
        public string $url,
        public string $title,
        public string $description,
        public ?string $creatorEmail = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'platform'    => $this->platform,
            'starts_at'   => $this->startsAt,
            'ends_at'     => $this->endsAt,
            'url'         => $this->url,
            'title'       => $this->title,
            'description' => $this->description,
        ];
    }

    public static function fromArray(array $data): EventDTO
    {
        return new self(
            $data['id'],
            $data['meeting_platform'],
            $data['start_time'],
            $data['end_time'],
            $data['meeting_url'],
            $data['raw']['summary'] ?? '',
            $data['raw']['description'] ?? '',
            $data['raw']['creator']['email'] ?? null,
        );
    }
}
