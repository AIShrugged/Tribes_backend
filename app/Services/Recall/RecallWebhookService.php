<?php

namespace App\Services\Recall;

use App\Exceptions\AppException;
use App\Services\Recall\Handlers\CalendarSyncEventHandler;
use App\Services\Recall\Handlers\CalendarUpdateHandler;
use App\Services\Recall\Payloads\CalendarSyncEventPayload;
use App\Services\Recall\Payloads\CalendarUpdatePayload;

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
        ]
    ];

    public static function handle(array $data): void
    {
        $eventName = $data['event'];

        if (!isset(self::EVENTS[$eventName])) {
            throw new AppException('No handler for event: ' . $eventName, 'RECALL_WEBHOOK_NO_HANDLER');
        }

        $event = self::EVENTS[$eventName];

        /** @var RecallPayloadInterface $event['payload'] */
        $payload = $event['payload']::fromArray($data['data']);

        (new $event['handler'])->handle($payload);
    }
}
