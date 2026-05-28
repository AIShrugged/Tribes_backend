<?php

namespace App\Services\Recall\Handlers;

use App\Exceptions\AppException;
use App\Models\Source;
use App\Services\Recall\BotSchedulingService;
use App\Services\Recall\CalendarEventSyncService;
use App\Services\RecallEventService;
use App\Services\Recall\Payloads\CalendarUpdatePayload;
use App\Services\Recall\RecallEventHandlerInterface;
use App\Services\Recall\RecallPayloadInterface;
use App\Services\RecallCalendarService;

class CalendarUpdateHandler implements RecallEventHandlerInterface
{
    public function __construct(
        private readonly CalendarEventSyncService $calendarEventSyncService,
        private readonly BotSchedulingService $botSchedulingService,
    ) {
    }

    public function handle(CalendarUpdatePayload|RecallPayloadInterface $payload): void
    {
        $source = Source::firstWhere('external_id', $payload->calendarId);

        if (!$source) {
            throw new AppException('Invalid source', 'EVENT_SOURCE_NOT_FOUND');
        }

        if (!RecallCalendarService::isConnected($source->external_id)) {
            $source->disconnect();
            $this->botSchedulingService->deactivateUpcomingBotsForSource($source);

            return;
        }

        $eventService = new RecallEventService($source);

        foreach ($eventService->getAllByCalendar() as $eventDTO) {
            $this->calendarEventSyncService->sync($source, $eventDTO, []);
        }
    }
}
