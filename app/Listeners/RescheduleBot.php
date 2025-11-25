<?php

namespace App\Listeners;

use App\Events\CalendarEventChanged;
use App\Services\Recall\RecallBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RescheduleBot
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(CalendarEventChanged $event): void
    {
        app(RecallBotService::class)->schedule($event->calendarEventId);
    }
}
