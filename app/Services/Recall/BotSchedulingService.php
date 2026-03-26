<?php

namespace App\Services\Recall;

use App\Enums\BotEventType;
use App\Models\Bot;
use App\Models\CalendarEvent;
use Illuminate\Support\Facades\DB;

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
        DB::transaction(function () use ($calendarEvent) {
            $existingBot = Bot::where('meeting_url', $calendarEvent->url)
                ->where('is_active', true)
                ->first();

            if ($existingBot) {
                $calendarEvent->bot()->associate($existingBot);
                $calendarEvent->save();

                return;
            }

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
            return;
        }

        DB::transaction(function () use ($calendarEvent, $bot) {
            $this->recallBotService->removeBot($calendarEvent);
            $bot->deactivate();
        });
    }

    /**
     * Handle the require/unrequire bot toggle from user.
     */
    public function handleRequirement(CalendarEvent $calendarEvent): void
    {
        if (!$calendarEvent->required_bot && !$calendarEvent->bot_id) {
            return;
        }

        if ($this->shouldRemoveBot($calendarEvent)) {
            $this->remove($calendarEvent);

            return;
        }

        if ($calendarEvent->required_bot && !$calendarEvent->bot?->is_active) {
            $this->schedule($calendarEvent);
        }
    }

    private function shouldRemoveBot(CalendarEvent $calendarEvent): bool
    {
        return !$calendarEvent->required_bot
            && $calendarEvent->bot
            && $calendarEvent->bot->is_active;
    }
}
