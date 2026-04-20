<?php

namespace App\Listeners;

use App\Events\CalendarEventChanged;
use App\Services\Recall\BotSchedulingService;
use Illuminate\Support\Facades\Log;

class RescheduleBot
{
    public function __construct(
        private readonly BotSchedulingService $botSchedulingService,
    ) {
    }

    public function handle(CalendarEventChanged $event): void
    {
        Log::info('RescheduleBot: handling CalendarEventChanged', [
            'calendar_event_id' => $event->calendarEvent->id,
            'bot_id' => $event->calendarEvent->bot_id,
            'required_bot' => $event->calendarEvent->isRequiredBot(),
            'bot_is_active' => $event->calendarEvent->bot?->is_active,
            'force_reschedule' => $event->forceReschedule,
        ]);

        $this->botSchedulingService->handleRequirement($event->calendarEvent, $event->forceReschedule);
    }
}
