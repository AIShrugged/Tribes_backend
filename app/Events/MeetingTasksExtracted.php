<?php

namespace App\Events;

use App\Models\CalendarEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingTasksExtracted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CalendarEvent $calendarEvent,
        public readonly Collection $issues,
    ) {}
}
