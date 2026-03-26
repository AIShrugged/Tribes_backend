<?php

namespace App\Services\Recall\Handlers;

use App\Enums\BotEventType;
use App\Exceptions\AppException;
use App\Jobs\ParseTranscriptJob;
use App\Models\Bot;
use App\Services\Recall\Payloads\TranscriptDonePayload;
use App\Services\Recall\RecallEventHandlerInterface;
use App\Services\Recall\RecallPayloadInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranscriptDoneHandler implements RecallEventHandlerInterface
{
    private const API_URL = 'https://us-west-2.recall.ai/api/v1/bot/%s/';

    public function handle(TranscriptDonePayload|RecallPayloadInterface $payload): void
    {
        $bot = Bot::firstWhere('external_id', $payload->botId);

        if (!$bot) {
            throw new AppException("Bot '{$payload->botId}' not found", 'BOT_NOT_FOUND');
        }

        $url = sprintf(self::API_URL, $payload->botId);

        $response = Http::withHeader('Authorization', config('services.recall.api_token'))
            ->get($url);

        if (!$response->successful()) {
            throw new AppException($response->json()['message'], 'RECALL_GENERIC_ERROR');
        }

        $bot->logEvent(BotEventType::TRANSCRIPT_DONE);

        $downloadUrl = $response->json()['recordings'][0]['media_shortcuts']['transcript']['data']['download_url'];

        // Dispatch transcript parsing for ALL calendar events linked to this bot
        foreach ($bot->calendarEvents as $calendarEvent) {
            ParseTranscriptJob::dispatch($calendarEvent, $downloadUrl);
        }
    }
}
