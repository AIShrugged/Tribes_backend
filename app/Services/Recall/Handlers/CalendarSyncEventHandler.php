<?php

namespace App\Services\Recall\Handlers;

use App\Events\CalendarEventChanged;
use App\Exceptions\AppException;
use App\Models\CalendarEvent;
use App\Models\Source;
use App\Services\Recall\Payloads\CalendarSyncEventPayload;
use App\Services\Recall\RecallEventHandlerInterface;
use App\Services\Recall\RecallPayloadInterface;
use Carbon\Carbon;
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
            $calendarEvent = $source->calendarEvents()->updateOrCreate([
                'external_id' => $event['id']
            ], [
                'platform'    => $event['meeting_platform'],
                'url'         => $event['meeting_url'],
                'title'       => $event['raw']['summary'],
                'description' => $event['raw']['description'] ?? '',
                'starts_at'   => Carbon::parse($event['start_time']),
                'ends_at'     => Carbon::parse($event['end_time']),
            ]);

            CalendarEventChanged::dispatch($calendarEvent->id);
        }
    }
}
