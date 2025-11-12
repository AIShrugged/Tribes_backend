<?php

namespace App\Services;

use App\Domain\DTO\EventDTO;

interface SourceEventServiceInterface
{
    /** @return EventDTO[] */
    public function getAllByCalendar(): array;
}
