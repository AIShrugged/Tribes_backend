<?php

namespace App\Services\Paperclip;

use App\Exceptions\AppException;
use App\Services\Paperclip\Handlers\AgentRunCancelledHandler;
use App\Services\Paperclip\Handlers\AgentRunFailedHandler;
use App\Services\Paperclip\Handlers\AgentRunFinishedHandler;
use App\Services\Paperclip\Handlers\AgentRunStartedHandler;
use App\Services\Paperclip\Payloads\AgentRunPayload;

class PaperclipWebhookService
{
    protected const EVENTS = [
        'agent.run.started' => [
            'handler' => AgentRunStartedHandler::class,
            'payload' => AgentRunPayload::class,
        ],
        'agent.run.finished' => [
            'handler' => AgentRunFinishedHandler::class,
            'payload' => AgentRunPayload::class,
        ],
        'agent.run.failed' => [
            'handler' => AgentRunFailedHandler::class,
            'payload' => AgentRunPayload::class,
        ],
        'agent.run.cancelled' => [
            'handler' => AgentRunCancelledHandler::class,
            'payload' => AgentRunPayload::class,
        ],
    ];

    public static function handle(array $data): void
    {
        $eventName = $data['event'] ?? null;

        if (! $eventName || ! isset(self::EVENTS[$eventName])) {
            throw new AppException(
                'No handler for Paperclip event: '.$eventName,
                'PAPERCLIP_WEBHOOK_NO_HANDLER'
            );
        }

        $event = self::EVENTS[$eventName];

        /** @var PaperclipPayloadInterface $payload */
        $payload = $event['payload']::fromArray($data['data'] ?? []);

        app($event['handler'])->handle($payload);
    }
}
