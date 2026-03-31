<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Services\Agenda\UpcomingAgendaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateUpcomingAgendaJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CalendarEvent $calendarEvent,
    ) {}

    public function handle(UpcomingAgendaService $service): void
    {
        $service->generateForEvent($this->calendarEvent);
    }
}
