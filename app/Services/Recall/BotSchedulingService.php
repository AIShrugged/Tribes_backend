<?php

namespace App\Services\Recall;

use App\Enums\BotEventType;
use App\Events\CalendarEventChanged;
use App\Models\Bot;
use App\Models\CalendarEvent;
use App\Models\Source;
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
     *
     * By default this is idempotent: if an active bot already exists after
     * acquiring a row-level lock, no additional Recall scheduling call is made.
     * Set $forceRecreate=true to explicitly refresh an active bot (e.g. when a
     * user explicitly clicks "require bot").
     */
    public function schedule(CalendarEvent $calendarEvent, bool $forceRecreate = false): void
    {
        Log::info('BotSchedulingService: schedule requested', [
            'calendar_event_id' => $calendarEvent->id,
            'calendar_event_url' => $calendarEvent->url,
            'bot_id' => $calendarEvent->bot_id,
            'force_recreate' => $forceRecreate,
        ]);

        DB::transaction(function () use ($calendarEvent, $forceRecreate) {
            // Re-fetch with a row-level lock so concurrent schedule() calls
            // serialise here and only one proceeds to create a bot.
            $calendarEvent = CalendarEvent::query()
                ->with('bot')
                ->lockForUpdate()
                ->findOrFail($calendarEvent->id);

            if (!$calendarEvent->getRecallExternalId()) {
                Log::info('BotSchedulingService: host Recall event id is not known yet, skipping schedule', [
                    'calendar_event_id' => $calendarEvent->id,
                    'meeting_url' => $calendarEvent->url,
                ]);

                return;
            }

            if ($calendarEvent->bot?->is_active && !$forceRecreate) {
                Log::info('BotSchedulingService: active bot already exists, skipping schedule', [
                    'calendar_event_id' => $calendarEvent->id,
                    'bot_id' => $calendarEvent->bot->id,
                    'meeting_url' => $calendarEvent->url,
                ]);

                return;
            }

            if ($calendarEvent->bot?->is_active) {
                Log::info('BotSchedulingService: recreating active bot', [
                    'calendar_event_id' => $calendarEvent->id,
                    'bot_id' => $calendarEvent->bot->id,
                    'meeting_url' => $calendarEvent->url,
                ]);

                $this->recallBotService->removeBot($calendarEvent);
                $calendarEvent->bot->deactivate();
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
     * Send a bot to a meeting that is already running.
     *
     * This intentionally uses Recall's direct bot endpoint by meeting URL,
     * because the calendar-event endpoint can return 404 for stale or missing
     * Recall calendar event IDs while the Meet URL itself is still valid.
     */
    public function joinMeetingNow(CalendarEvent $calendarEvent): void
    {
        Log::info('BotSchedulingService: join now requested', [
            'calendar_event_id' => $calendarEvent->id,
            'calendar_event_url' => $calendarEvent->url,
            'bot_id' => $calendarEvent->bot_id,
        ]);

        DB::transaction(function () use ($calendarEvent) {
            $calendarEvent = CalendarEvent::query()
                ->with('bot')
                ->lockForUpdate()
                ->findOrFail($calendarEvent->id);

            if ($calendarEvent->bot?->is_active) {
                $calendarEvent->bot->deactivate();
            }

            $botDTO = $this->recallBotService->joinMeetingNow($calendarEvent);

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
        Log::info('BotSchedulingService: remove requested', [
            'calendar_event_id' => $calendarEvent->id,
            'bot_id' => $calendarEvent->bot_id,
            'meeting_url' => $calendarEvent->url,
        ]);

        DB::transaction(function () use ($calendarEvent) {
            // Re-fetch with a row-level lock so concurrent remove() calls
            // serialise and only one actually calls Recall.
            $lockedEvent = CalendarEvent::query()
                ->with('bot')
                ->lockForUpdate()
                ->findOrFail($calendarEvent->id);

            $activeBot = $lockedEvent->bot;
            if (!$activeBot || !$activeBot->is_active) {
                Log::info('BotSchedulingService: remove skipped after lock', [
                    'calendar_event_id' => $lockedEvent->id,
                    'bot_id' => $activeBot?->id,
                    'bot_is_active' => $activeBot?->is_active,
                ]);

                return;
            }

            Log::info('BotSchedulingService: removing bot via Recall', [
                'calendar_event_id' => $lockedEvent->id,
                'bot_id' => $activeBot->id,
                'meeting_url' => $lockedEvent->url,
            ]);

            $this->recallBotService->removeBot($lockedEvent);
            $activeBot->deactivate();
        });
    }

    public function deactivateUpcomingBotsForSource(Source $source): int
    {
        $deactivated = 0;

        $source->calendarEvents()
            ->where('starts_at', '>', now())
            ->with('bot')
            ->get()
            ->each(function (CalendarEvent $calendarEvent) use (&$deactivated, $source) {
                if (!$calendarEvent->bot?->is_active) {
                    return;
                }

                Log::info('BotSchedulingService: deactivating bot for disconnected source', [
                    'source_id' => $source->id,
                    'calendar_event_id' => $calendarEvent->id,
                    'bot_id' => $calendarEvent->bot->id,
                    'meeting_url' => $calendarEvent->url,
                ]);

                $calendarEvent->bot->deactivate();
                $deactivated++;
            });

        return $deactivated;
    }

    /**
     * Record the bot requirement for a user's source on one event, then reschedule.
     *
     * Single source of truth for the require/unrequire toggle: it updates the
     * creator's calendar_event_source pivot (required_bot + the organization the
     * bot is connected from) and dispatches CalendarEventChanged so the bot is
     * scheduled/removed via {@see handleRequirement}. Shared by the single-event
     * and whole-series paths (see BotController::require) so the logic lives once.
     */
    public function setRequirement(CalendarEvent $calendarEvent, int $userId, bool $required, ?int $organizationId): void
    {
        $source = $calendarEvent->sources()->where('user_id', $userId)->first();

        if ($source) {
            $calendarEvent->sources()->updateExistingPivot($source->id, [
                'required_bot'    => $required,
                'organization_id' => $required ? $organizationId : null,
            ]);
        }

        CalendarEventChanged::dispatch($calendarEvent, true);
    }

    /**
     * Handle the require/unrequire bot toggle from user.
     * Checks pivot table — bot is required if ANY participant's source requires it.
     */
    public function handleRequirement(CalendarEvent $calendarEvent, bool $forceReschedule = false): void
    {
        $anyoneRequires = $calendarEvent->isRequiredBot();
        $botIsActive = (bool) $calendarEvent->bot?->is_active;

        Log::info('BotSchedulingService: handleRequirement', [
            'calendar_event_id' => $calendarEvent->id,
            'required_bot' => $anyoneRequires,
            'bot_id' => $calendarEvent->bot_id,
            'bot_is_active' => $botIsActive,
            'force_reschedule' => $forceReschedule,
            'meeting_url' => $calendarEvent->url,
        ]);

        if (!$anyoneRequires && $botIsActive) {
            $this->remove($calendarEvent);

            return;
        }

        if ($anyoneRequires) {
            $this->schedule($calendarEvent, $forceReschedule);

            return;
        }

        Log::info('BotSchedulingService: no action taken', [
            'calendar_event_id' => $calendarEvent->id,
            'required_bot' => $anyoneRequires,
            'bot_is_active' => $botIsActive,
        ]);
    }
}
