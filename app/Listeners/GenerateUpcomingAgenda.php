<?php

namespace App\Listeners;

use App\Events\TranscriptParsed;
use App\Jobs\GenerateUpcomingAgendaJob;

class GenerateUpcomingAgenda
{
    public function handle(TranscriptParsed $event): void
    {
        GenerateUpcomingAgendaJob::dispatch($event->calendarEvent);
    }
}
