<?php

namespace App\Services\Recall;

use App\Domain\DTO\EventDTO;
use App\Events\CalendarEventChanged;
use App\Models\CalendarEvent;
use App\Models\Profile;
use App\Models\Source;
use Carbon\Carbon;

class CalendarEventSyncService
{
    public function sync(Source $source, EventDTO $eventDTO, array $attendees): ?CalendarEvent
    {
        $startTime = Carbon::parse($eventDTO->startsAt)->setTimezone(config('app.timezone'));
        $endTime = Carbon::parse($eventDTO->endsAt)->setTimezone(config('app.timezone'));

        if ($startTime->lte(Carbon::now())) {
            return null;
        }

        $calendarEvent = $source->calendarEvents()->updateOrCreate(
            ['external_id' => $eventDTO->externalId],
            [
                'platform'    => $eventDTO->platform,
                'url'         => $eventDTO->url,
                'title'       => $eventDTO->title,
                'description' => $eventDTO->description,
                'starts_at'   => $startTime,
                'ends_at'     => $endTime,
            ]
        );

        $this->syncAttendees($calendarEvent, $attendees);
        $botDTO = app(RecallBotService::class)->schedule($calendarEvent);

        $calendarEvent->bot()->updateOrCreate(
            ['external_id' => $botDTO->externalId],
            ['deduplication_key' => $botDTO->deduplicationKey]
        );

        $calendarEvent->requiredBot(true);

        CalendarEventChanged::dispatch($calendarEvent);

        return $calendarEvent;
    }

    protected function syncAttendees(CalendarEvent $calendarEvent, array $attendees): void
    {
        $profiles = [];

        foreach ($attendees as $attendee) {
            $profiles[] = Profile::firstOrCreate(['email' => $attendee->email]);
        }

        $calendarEvent->profiles()->sync($profiles);
    }
}
