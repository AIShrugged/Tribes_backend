<?php

namespace App\Jobs;

use App\Models\CalendarEvent;
use App\Services\Agenda\AgendaService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAgendaJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public CalendarEvent $calendarEvent,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->calendarEvent->id;
    }

    public function handle(AgendaService $service): void
    {
        $service->generateForEvent($this->calendarEvent);
        SendAgendaNotificationsJob::dispatch($this->calendarEvent);
    }
}
