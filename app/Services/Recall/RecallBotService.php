<?php

namespace App\Services\Recall;

use App\Exceptions\AppException;
use App\Models\Bot;
use App\Models\CalendarEvent;
use Illuminate\Support\Facades\Http;

class RecallBotService
{
    private const API_URL = 'https://us-west-2.recall.ai/api/v2/calendar-events/%s/bot/';

    public function schedule(int $calendarEventId): void
    {
        $calendarEvent = CalendarEvent::find($calendarEventId);

        if (!$calendarEvent) {
            throw new AppException(
                'Tried to schedule a bot for an event that does not exist.',
                'BOT_EVENT_NOT_FOUND'
            );
        }

        $url = sprintf(self::API_URL, $calendarEvent->external_id);

        $calendarEvent->bot()->updateOrCreate([], ['deduplication_key' => md5($calendarEvent->external_id)]);

        $response = Http::withHeader('Authorization', config('services.recall.api_token'))
            ->post($url, [
                'deduplication_key' => md5($calendarEvent->external_id),
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
    }
}
