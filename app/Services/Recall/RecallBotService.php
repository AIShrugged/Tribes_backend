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

    private PendingRequest $httpClient;

    public function __construct()
    {
        $this->httpClient = Http::withHeader('Authorization', config('services.recall.api_token'));
    }

    public function schedule(CalendarEvent $calendarEvent): BotDTO
    {
        $url = sprintf(self::API_URL, $calendarEvent->external_id);

        $deduplicationKey = md5($calendarEvent->external_id);

        $response = $this->httpClient->post($url, [
            'deduplication_key' => $deduplicationKey,
            'bot_config'        => [
                'bot_name'         => 'Spodial Notetaker',
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

    public function removeBot(CalendarEvent $calendarEvent): void
    {
        $url = sprintf(self::API_URL, $calendarEvent->external_id);

        $response = $this->httpClient->delete($url);

        if (!$response->successful()) {
            throw new AppException($response->json()['message'], 'RECALL_GENERIC_ERROR');
        }

        Log::info('Bot removed', $response->json());
    }
}
