<?php

namespace App\Services\Recall\Handlers;

use App\Exceptions\AppException;
use App\Models\Source;
use App\Services\Recall\Payloads\CalendarUpdatePayload;
use App\Services\Recall\RecallEventHandlerInterface;
use App\Services\Recall\RecallPayloadInterface;
use App\Services\RecallCalendarService;

class CalendarUpdateHandler implements RecallEventHandlerInterface
{

    public function handle(CalendarUpdatePayload|RecallPayloadInterface $payload): void
    {
        $source = Source::firstWhere('external_id', $payload->calendarId);

        if (!$source) {
            throw new AppException('Invalid source', 'EVENT_SOURCE_NOT_FOUND');
        }

        if (!RecallCalendarService::isConnected($source->external_id)) {
            $source->disconnect();
        }
    }
}
