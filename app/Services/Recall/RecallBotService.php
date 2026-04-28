<?php

namespace App\Services\Recall;

use App\Domain\DTO\BotDTO;
use App\Exceptions\AppException;
use App\Models\Bot;
use App\Models\CalendarEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecallBotService
{
    private const API_URL = 'https://us-west-2.recall.ai/api/v2/calendar-events/%s/bot/';
    private const DIRECT_BOT_API_URL = 'https://us-west-2.recall.ai/api/v1/bot/';

    private PendingRequest $httpClient;

    public function __construct()
    {
        $this->httpClient = Http::withHeader('Authorization', config('services.recall.api_token'));
    }

    public function schedule(CalendarEvent $calendarEvent): BotDTO
    {
        $externalId = $calendarEvent->getRecallExternalId() ?? $calendarEvent->external_id;
        $url = sprintf(self::API_URL, $externalId);

        $deduplicationKey = md5($externalId);

        $response = $this->httpClient->post($url, [
            'deduplication_key' => $deduplicationKey,
            'bot_config'        => [
                'bot_name'         => 'Tribes Notetaker',
                'recording_config' => [
                    'transcript' => [
                        'provider' => [
                            'recallai_streaming' => [
                                'mode' => 'prioritize_accuracy'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        if (!$response->successful()) {
            throw new AppException($response->json()['message'], 'RECALL_GENERIC_ERROR');
        }

        Log::info('Bot scheduled', $response->json());

        $botExternalId = null;
        foreach ($response->json()['bots'] as $bot) {
            if ($deduplicationKey == $bot['deduplication_key']) {
                $botExternalId = $bot['bot_id'];
            }
        }

        if (!$botExternalId) {
            throw new AppException('Bot not found', 'RECALL_GENERIC_ERROR');
        }

        return new BotDTO($botExternalId, $deduplicationKey);
    }

    public function joinMeetingNow(CalendarEvent $calendarEvent): BotDTO
    {
        $deduplicationKey = md5('join-now:' . $calendarEvent->id . ':' . \Illuminate\Support\Str::uuid());

        $response = $this->httpClient->post(self::DIRECT_BOT_API_URL, [
            'meeting_url'      => $calendarEvent->url,
            'bot_name'         => 'Tribes Notetaker',
            'recording_config' => [
                'transcript' => [
                    'provider' => [
                        'recallai_streaming' => [
                            'mode' => 'prioritize_accuracy'
                        ]
                    ]
                ]
            ],
            'metadata'         => [
                'calendar_event_id' => (string) $calendarEvent->id,
            ],
        ]);

        if (!$response->successful()) {
            throw new AppException($response->json('message') ?? $response->body(), 'RECALL_GENERIC_ERROR');
        }

        Log::info('Bot joined meeting now', $response->json());

        $botExternalId = $response->json('id') ?? $response->json('bot_id');

        if (!$botExternalId) {
            throw new AppException('Bot not found', 'RECALL_GENERIC_ERROR');
        }

        return new BotDTO($botExternalId, $deduplicationKey);
    }

    public function removeBot(CalendarEvent $calendarEvent): void
    {
        $externalId = $calendarEvent->getRecallExternalId() ?? $calendarEvent->external_id;
        $url = sprintf(self::API_URL, $externalId);

        $response = $this->httpClient->delete($url);

        if (!$response->successful()) {
            throw new AppException($response->json()['message'], 'RECALL_GENERIC_ERROR');
        }

        Log::info('Bot removed', $response->json());
    }
}
