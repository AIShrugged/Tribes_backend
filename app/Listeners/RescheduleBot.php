<?php

namespace App\Listeners;

use App\Events\CalendarEventChanged;
use App\Services\Recall\BotSchedulingService;

class RescheduleBot
{
    public function __construct(
        private readonly BotSchedulingService $botSchedulingService,
    ) {
    }

    public function handle(CalendarEventChanged $event): void
    {
        $this->botSchedulingService->handleRequirement($event->calendarEvent);
    }
}
