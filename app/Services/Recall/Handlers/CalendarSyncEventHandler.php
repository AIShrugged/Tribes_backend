<?php

namespace App\Services\Recall\Handlers;

use App\Domain\DTO\EventDTO;
use App\Domain\DTO\ProfileDTO;
use App\Exceptions\AppException;
use App\Models\Source;
use App\Services\Recall\CalendarEventSyncService;
use App\Services\Recall\Payloads\CalendarSyncEventPayload;
use App\Services\Recall\RecallEventHandlerInterface;
use App\Services\Recall\RecallPayloadInterface;
use Illuminate\Support\Facades\Http;

class CalendarSyncEventHandler implements RecallEventHandlerInterface
{
    protected const API_URL = 'https://us-west-2.recall.ai/api/v2/calendar-events/';

    public function handle(CalendarSyncEventPayload|RecallPayloadInterface $payload): void
    {
        $response = Http::withHeader('Authorization', config('services.recall.api_token'))
            ->get(self::API_URL, [
                'calendar_id'     => $payload->calendarId,
                'updated_at__gte' => $payload->lastUpdated
            ]);

        if (!$response->successful()) {
            throw new AppException($response->json('message'), 'RECALL_GENERIC_ERROR');
        }

        $source = Source::firstWhere('external_id', $payload->calendarId);

        if (!$source) {
            throw new AppException('Source not found', 'SOURCE_NOT_FOUND');
        }

        foreach ($response['results'] as $event) {
            if (!$event['meeting_platform'] || !$event['meeting_url']) {
                continue;
            }

            $eventDTO = EventDTO::fromArray($event);

            $profiles = [];
            foreach (($event['raw']['attendees'] ?? []) as $attendee) {
                $profiles[] = ProfileDTO::fromArray($attendee);
            }

            app(CalendarEventSyncService::class)->sync($source, $eventDTO, $profiles);
        }
    }
}
