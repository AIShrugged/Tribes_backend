<?php

namespace App\Listeners;

use App\Events\MeetingTasksExtracted;
use App\Services\Decisions\LinkDecisionsToIssuesService;
use Illuminate\Support\Facades\Log;

class LinkDecisionsAfterTasksExtracted
{
    public function __construct(
        private readonly LinkDecisionsToIssuesService $linker,
    ) {}

    public function handle(MeetingTasksExtracted $event): void
    {
        try {
            $count = $this->linker->link($event->calendarEvent, $event->issues);

            if ($count > 0) {
                Log::info('LinkDecisionsAfterTasksExtracted: linked decisions to issues', [
                    'calendar_event_id' => $event->calendarEvent->id,
                    'links'             => $count,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('LinkDecisionsAfterTasksExtracted: failed', [
                'calendar_event_id' => $event->calendarEvent->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }
}
