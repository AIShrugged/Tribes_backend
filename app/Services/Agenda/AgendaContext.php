<?php

namespace App\Services\Agenda;

use App\Models\MeetingAgenda;
use App\Models\MeetingSeriesState;
use Illuminate\Support\Collection;

class AgendaContext
{
    public function __construct(
        public readonly ?string $orgContext,
        public readonly ?MeetingSeriesState $seriesState,
        public readonly Collection $previousEvents,
        public readonly Collection $upcomingAgendas,
        public readonly ?MeetingAgenda $previousAgenda,
    ) {}
}
