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
        public bool $hasBot,
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
            'has_bot'     => $this->hasBot,
        ];
    }
}
