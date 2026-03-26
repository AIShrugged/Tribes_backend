<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Services\Agenda\AgendaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAgendaJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CalendarEvent $calendarEvent,
    ) {}

    public function handle(AgendaService $service): void
    {
        $service->generateForEvent($this->calendarEvent);
    }
}
