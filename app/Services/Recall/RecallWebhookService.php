<?php

namespace App\Services\Recall;

use App\Services\Recall\Handlers\CalendarSyncEventHandler;
use Illuminate\Support\Facades\Log;
use App\Services\Recall\Handlers\CalendarUpdateHandler;
use App\Services\Recall\Handlers\TranscriptDoneHandler;
use App\Services\Recall\Payloads\CalendarSyncEventPayload;
use App\Services\Recall\Payloads\CalendarUpdatePayload;
use App\Services\Recall\Payloads\TranscriptDonePayload;

class RecallWebhookService
{
    protected const EVENTS = [
        'calendar.update' => [
            'handler' => CalendarUpdateHandler::class,
            'payload' => CalendarUpdatePayload::class,
        ],
        'calendar.sync_events' => [
            'handler' => CalendarSyncEventHandler::class,
            'payload' => CalendarSyncEventPayload::class,
        ],
        'transcript.done' => [
            'handler' => TranscriptDoneHandler::class,
            'payload' => TranscriptDonePayload::class,
        ]
    ];

    public static function handle(array $data): void
    {
        $eventName = $data['event'];

        if (!isset(self::EVENTS[$eventName])) {
            Log::info('Recall webhook: unknown event, skipping', ['event' => $eventName]);
            return;
        }

        $event = self::EVENTS[$eventName];

        /** @var RecallPayloadInterface $event['payload'] */
        $payload = $event['payload']::fromArray($data['data']);

        app($event['handler'])->handle($payload);
    }
}
