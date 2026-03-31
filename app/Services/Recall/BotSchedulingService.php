<?php

namespace App\Services\Recall;

use App\Enums\BotEventType;
use App\Models\Bot;
use App\Models\CalendarEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BotSchedulingService
{
    public function __construct(
        private readonly RecallBotService $recallBotService,
    ) {
    }

    /**
     * Schedule a bot for the calendar event.
     * If a bot already exists for the same meeting URL, reuse it.
     */
    public function schedule(CalendarEvent $calendarEvent): void
    {
        Log::info('BotSchedulingService: schedule requested', [
            'calendar_event_id' => $calendarEvent->id,
            'calendar_event_url' => $calendarEvent->url,
            'bot_id' => $calendarEvent->bot_id,
        ]);

        DB::transaction(function () use ($calendarEvent) {
            $existingBot = Bot::where('meeting_url', $calendarEvent->url)
                ->where('is_active', true)
                ->first();

            if ($existingBot) {
                Log::info('BotSchedulingService: reusing existing active bot', [
                    'calendar_event_id' => $calendarEvent->id,
                    'bot_id' => $existingBot->id,
                    'meeting_url' => $calendarEvent->url,
                ]);

                $calendarEvent->bot()->associate($existingBot);
                $calendarEvent->save();

                return;
            }

            Log::info('BotSchedulingService: creating bot via Recall', [
                'calendar_event_id' => $calendarEvent->id,
                'meeting_url' => $calendarEvent->url,
            ]);

            $botDTO = $this->recallBotService->schedule($calendarEvent);

            $bot = Bot::create([
                'external_id'      => $botDTO->externalId,
                'deduplication_key' => $botDTO->deduplicationKey,
                'meeting_url'      => $calendarEvent->url,
                'is_active'        => true,
            ]);

            $bot->logEvent(BotEventType::SCHEDULED);

            $calendarEvent->bot()->associate($bot);
            $calendarEvent->save();
        });
    }

    /**
     * Remove the bot from the meeting.
     * Deactivates the bot but keeps the record for transcript delivery.
     */
    public function remove(CalendarEvent $calendarEvent): void
    {
        $bot = $calendarEvent->bot;

        if (!$bot || !$bot->is_active) {
            Log::info('BotSchedulingService: remove skipped', [
                'calendar_event_id' => $calendarEvent->id,
                'bot_id' => $bot?->id,
                'bot_is_active' => $bot?->is_active,
            ]);

            return;
        }

        Log::info('BotSchedulingService: removing bot via Recall', [
            'calendar_event_id' => $calendarEvent->id,
            'bot_id' => $bot->id,
            'meeting_url' => $calendarEvent->url,
        ]);

        DB::transaction(function () use ($calendarEvent, $bot) {
            $this->recallBotService->removeBot($calendarEvent);
            $bot->deactivate();
        });
    }

    /**
     * Handle the require/unrequire bot toggle from user.
     * Checks pivot table — bot is required if ANY participant's source requires it.
     */
    public function handleRequirement(CalendarEvent $calendarEvent): void
    {
        $anyoneRequires = $calendarEvent->isRequiredBot();
        $botIsActive = (bool) $calendarEvent->bot?->is_active;

        Log::info('BotSchedulingService: handleRequirement', [
            'calendar_event_id' => $calendarEvent->id,
            'required_bot' => $anyoneRequires,
            'bot_id' => $calendarEvent->bot_id,
            'bot_is_active' => $botIsActive,
            'meeting_url' => $calendarEvent->url,
        ]);

        if (!$anyoneRequires && $botIsActive) {
            $this->remove($calendarEvent);

            return;
        }

        if ($anyoneRequires && !$botIsActive) {
            $this->schedule($calendarEvent);

            return;
        }

        Log::info('BotSchedulingService: no action taken', [
            'calendar_event_id' => $calendarEvent->id,
            'required_bot' => $anyoneRequires,
            'bot_is_active' => $botIsActive,
        ]);
    }
}
